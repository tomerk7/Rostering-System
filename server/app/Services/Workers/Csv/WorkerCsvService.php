<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Enums\RoleCode;
use App\Events\WorkersImported;
use App\Exceptions\Workers\WorkerContractException;
use App\Jobs\ExportWorkersJob;
use App\Jobs\ImportWorkersJob;
use App\Models\Contract;
use App\Models\ContractAvailability;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use App\Rules\IsraeliId;
use App\Services\Workers\WorkerContractValidator;
use Generator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;
use SplFileObject;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Worker CSV import/export and queued import/export orchestration.
 *
 * Fixed columns 0-6 hold worker/contract fields. Columns 7+ are shift columns
 * identified by header label (e.g. Shift_A). Each shift cell holds
 * a cron-style day expression. See prompts/db schema/schema.md.
 */
final class WorkerCsvService
{
    public const int FULL_NAME = 0;

    public const int ISRAELI_ID = 1;

    public const int ROLE = 2;

    public const int STATUS = 3;

    public const int HOURLY_COST = 4;

    public const int MIN_MONTHLY_HOURS = 5;

    public const int MAX_MONTHLY_HOURS = 6;

    public const int SHIFT_COLUMN_OFFSET = 7;

    /**
     * In-cell separator for day tokens. A pipe is used so commas never trigger Excel quoting.
     */
    public const string VALUE_SEPARATOR = '|';

    public const string DAY_RANGE_SEPARATOR = '-';

    public const string STATUS_ACTIVE = 'Active';

    public const string STATUS_INACTIVE = 'Inactive';

    public const string DEFAULT_STATUS = self::STATUS_ACTIVE;

    /**
     * Fixed column labels written before the dynamic shift columns.
     *
     * @var list<string>
     */
    public const array FIXED_HEADERS = [
        'full_name',
        'israeli_id',
        'role',
        'status',
        'hourly_cost',
        'min_monthly_hours',
        'max_monthly_hours',
    ];

    private const string IMPORT_STORAGE_DIR = 'worker-imports';

    private const string EXPORT_STORAGE_DIR = 'worker-exports';

    /**
     * Rows persisted per transaction. Bounds memory and lock duration while
     * keeping each chunk's bulk writes to a handful of queries.
     */
    private const int CHUNK_SIZE = 1000;

    /** @var Collection<int, Shift>|null */
    private ?Collection $orderedShifts = null;

    /** @var array<string, string>|null */
    private ?array $shiftCodeByColumnLabel = null;

    /**
     * Constructor.
     * 
     * @param WorkerContractValidator $contractValidator
     * @return void
     */
    public function __construct(
        private readonly WorkerContractValidator $contractValidator,
    ) {}

    /**
     * Build the full CSV header row: fixed columns plus one column per shift.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $shiftLabels = $this->orderedShifts()
            ->map(fn (Shift $shift): string => self::shiftColumnLabel($shift))
            ->values()
            ->all();

        return array_merge(self::FIXED_HEADERS, $shiftLabels);
    }

    /**
     * Format a shift code as the CSV column header (Shift_A, Shift_B, Shift_C).
     */
    public static function shiftColumnLabel(Shift $shift): string
    {
        return 'Shift_'.strtoupper($shift->code);
    }

    /**
     * Store an uploaded CSV and queue it for import.
     */
    public function queueImport(UploadedFile $file): string
    {
        $this->purgeAbandonedImportFiles();

        $importId = (string) Str::uuid();
        $storedPath = $file->storeAs(self::IMPORT_STORAGE_DIR, "{$importId}.csv", 'local');

        Cache::put($this->importCacheKey($importId), [
            'status' => 'processing',
        ], now()->addHour());

        ImportWorkersJob::dispatch($importId, $storedPath);

        return $importId;
    }

    /**
     * Process a queued worker CSV import.
     */
    public function processImport(string $importId, string $storedPath): void
    {
        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $result = $this->import($absolutePath);

            Cache::put($this->importCacheKey($importId), [
                'status' => 'completed',
                'result' => $result,
            ], now()->addHour());
        } finally {
            $this->removeImportFile($storedPath);
        }
    }

    /**
     * Remove a stored import CSV from disk.
     */
    public function removeImportFile(string $storedPath): void
    {
        if (! str_starts_with($storedPath, self::IMPORT_STORAGE_DIR.'/')) {
            Log::warning('Skipped deleting import file outside the import directory.', [
                'stored_path' => $storedPath,
            ]);

            return;
        }

        if (! Storage::disk('local')->delete($storedPath)) {
            return;
        }

        Log::info('Import file deleted.', ['stored_path' => $storedPath]);
    }

    /**
     * Delete import CSVs left behind when a queued job never ran.
     */
    private function purgeAbandonedImportFiles(): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::IMPORT_STORAGE_DIR)) {
            return;
        }

        foreach ($disk->files(self::IMPORT_STORAGE_DIR) as $path) {
            $disk->delete($path);
            Log::info('Abandoned import file deleted.', ['stored_path' => $path]);
        }
    }

    /**
     * Record a failed worker CSV import and remove the stored file.
     */
    public function markImportFailed(string $importId, string $storedPath, string $message): void
    {
        Cache::put($this->importCacheKey($importId), [
            'status' => 'failed',
            'message' => $message,
        ], now()->addHour());

        $this->removeImportFile($storedPath);
    }

    /**
     * Return the current state of a queued worker CSV import.
     *
     * @return array{
     *     status: 'not_found'|'processing'|'completed'|'failed',
     *     import_id: string,
     *     data?: array<string, mixed>,
     *     errors?: list<array{line: int, field: string, message: string}>,
     *     message?: string
     * }
     */
    public function getImportState(string $importId): array
    {
        $cached = Cache::get($this->importCacheKey($importId));

        if (! is_array($cached)) {
            return [
                'status' => 'not_found',
                'import_id' => $importId,
            ];
        }

        return match ($cached['status']) {
            'processing' => [
                'status' => 'processing',
                'import_id' => $importId,
            ],
            'completed' => $this->completedImportState($importId, $cached['result']),
            'failed' => [
                'status' => 'failed',
                'import_id' => $importId,
                'message' => $cached['message'] ?? 'Unknown error.',
            ],
            default => [
                'status' => 'not_found',
                'import_id' => $importId,
            ],
        };
    }

    /**
     * Queue a worker CSV export.
     */
    public function queueExport(): string
    {
        $exportId = (string) Str::uuid();
        $storedPath = self::EXPORT_STORAGE_DIR."/{$exportId}.csv";

        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'processing',
        ], now()->addHour());

        ExportWorkersJob::dispatch($exportId, $storedPath);

        return $exportId;
    }

    /**
     * Process a queued worker CSV export.
     */
    public function processExport(string $exportId, string $storedPath): void
    {
        Storage::disk('local')->makeDirectory(self::EXPORT_STORAGE_DIR);

        $handle = fopen(Storage::disk('local')->path($storedPath), 'w');

        if (! $handle) {
            throw new RuntimeException("Unable to open export file for writing: {$storedPath}");
        }

        try {
            fputcsv($handle, $this->headers());

            Worker::query()
                ->with(['role', 'contract.availability.shift'])
                ->orderBy('israeli_id')
                ->lazy()
                ->each(function (Worker $worker) use ($handle): void {
                    fputcsv($handle, $this->toRow($worker));
                });
        } finally {
            fclose($handle);
        }

        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'completed',
            'stored_path' => $storedPath,
            'filename' => 'workers-'.now()->format('Y-m-d').'.csv',
        ], now()->addHour());
    }

    /**
     * Record a failed worker CSV export and remove the stored file.
     */
    public function markExportFailed(string $exportId, string $storedPath, string $message): void
    {
        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'failed',
            'message' => $message,
        ], now()->addHour());

        Storage::disk('local')->delete($storedPath);
    }

    /**
     * Return the current state of a queued worker CSV export.
     *
     * @return array{
     *     status: 'not_found'|'processing'|'completed'|'failed',
     *     export_id: string,
     *     data?: array<string, mixed>,
     *     message?: string
     * }
     */
    public function getExportState(string $exportId): array
    {
        $cached = Cache::get($this->exportCacheKey($exportId));

        if (! is_array($cached)) {
            return [
                'status' => 'not_found',
                'export_id' => $exportId,
            ];
        }

        return match ($cached['status']) {
            'processing' => [
                'status' => 'processing',
                'export_id' => $exportId,
            ],
            'completed' => [
                'status' => 'completed',
                'export_id' => $exportId,
                'data' => [
                    'export_id' => $exportId,
                    'status' => 'completed',
                    'filename' => $cached['filename'],
                ],
            ],
            'failed' => [
                'status' => 'failed',
                'export_id' => $exportId,
                'message' => $cached['message'] ?? 'Unknown error.',
            ],
            default => [
                'status' => 'not_found',
                'export_id' => $exportId,
            ],
        };
    }

    /**
     * Stream a completed queued export and remove the stored file.
     */
    public function streamQueuedExport(string $exportId): StreamedResponse
    {
        $cached = Cache::get($this->exportCacheKey($exportId));

        if (! is_array($cached) || ($cached['status'] ?? '') !== 'completed') {
            abort(404, 'Worker export not found or not ready.');
        }

        /** @var string $storedPath */
        $storedPath = $cached['stored_path'];
        /** @var string $filename */
        $filename = $cached['filename'];

        return response()->streamDownload(
            function () use ($storedPath, $exportId): void {
                $stream = Storage::disk('local')->readStream($storedPath);

                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }

                Storage::disk('local')->delete($storedPath);
                Cache::forget($this->exportCacheKey($exportId));
            },
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * Import a worker CSV: stream, validate, dedupe, and upsert.
     *
     * Each row is validated independently and matched on `israeli_id` (the
     * upsert key) — existing workers are updated in place, new ones created.
     * Writes run as bulk upserts in chunked transactions; if a whole chunk
     * fails to persist it is rolled back and reported without aborting the rest.
     *
     * @return array{
     *     total: int,
     *     imported: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<array{line: int, field: string, message: string}>
     * }
     */
    public function import(string $path): array
    {
        $total = 0;
        $errors = [];

        $headerResult = $this->parseHeaderRow($path);
        $errors = array_merge($errors, $headerResult['errors']);

        /** @var array<int, array{label: string, code: string}> $shiftColumnMap */
        $shiftColumnMap = $headerResult['map'];

        if ($shiftColumnMap === []) {
            return [
                'total' => 0,
                'imported' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => $errors,
            ];
        }

        /** @var list<array<string, mixed>> $rows validated, persistence-ready rows */
        $rows = [];

        /** @var array<string, int> $seenIds line number where each israeli_id was first seen */
        $seenIds = [];

        foreach ($this->readDataRows($path) as $line => $row) {
            $total++;

            $result = $this->validateRow($row, $shiftColumnMap);

            if (! empty($result['errors'])) {
                foreach ($result['errors'] as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = ['line' => $line, 'field' => $field, 'message' => $message];
                    }
                }

                continue;
            }

            /** @var array<string, mixed> $data */
            $data = $result['data'];
            /** @var string $israeliId */
            $israeliId = $data['israeli_id'];

            if (isset($seenIds[$israeliId])) {
                $errors[] = [
                    'line' => $line,
                    'field' => 'israeli_id',
                    'message' => "Duplicate israeli_id within file (first seen on line {$seenIds[$israeliId]}).",
                ];

                continue;
            }

            $seenIds[$israeliId] = $line;
            $data['line'] = $line;
            $rows[] = $data;
        }

        ['created' => $created, 'updated' => $updated] = $this->persist($rows, $errors);

        if ($created + $updated > 0) {
            WorkersImported::dispatch($created, $updated);
        }

        return [
            'total' => $total,
            'imported' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $total - ($created + $updated),
            'errors' => $errors,
        ];
    }

    /**
     * @param array{
     *     total: int,
     *     imported: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<array{line: int, field: string, message: string}>
     * } $result
     * @return array{
     *     status: 'completed',
     *     import_id: string,
     *     data: array<string, mixed>,
     *     errors: list<array{line: int, field: string, message: string}>
     * }
     */
    private function completedImportState(string $importId, array $result): array
    {
        $errors = $result['errors'];
        unset($result['errors']);

        return [
            'status' => 'completed',
            'import_id' => $importId,
            'data' => $result,
            'errors' => $errors,
        ];
    }

    /**
     * Build a cache key for a queued worker CSV import.
     */
    private function importCacheKey(string $importId): string
    {
        return "worker-import:{$importId}";
    }

    /**
     * Build a cache key for a queued worker CSV export.
     */
    private function exportCacheKey(string $exportId): string
    {
        return "worker-export:{$exportId}";
    }

    /**
     * Build a single CSV row for a worker in fixed column order.
     *
     * @return list<string>
     */
    private function toRow(Worker $worker): array
    {
        $contract = $worker->contract;

        /** @var array<string, list<int>> $daysByShiftCode */
        $daysByShiftCode = [];

        if ($contract !== null) {
            foreach ($contract->availability as $slot) {
                $daysByShiftCode[(string) $slot->shift->code][] = (int) $slot->day_of_week;
            }
        }

        $row = [
            $worker->full_name,
            $worker->israeli_id,
            RoleCode::tryFrom($worker->role->code)?->label() ?? $worker->role->code,
            $worker->is_active ? self::STATUS_ACTIVE : self::STATUS_INACTIVE,
            (string) $contract?->hourly_cost,
            (string) $contract?->min_monthly_hours,
            (string) $contract?->max_monthly_hours,
        ];

        foreach ($this->orderedShifts() as $shift) {
            $days = $daysByShiftCode[$shift->code] ?? [];
            $row[] = $this->compressDays($days);
        }

        return $row;
    }

    /**
     * Compress sorted day numbers into a cron-style expression.
     *
     * @param  list<int>  $days
     */
    private function compressDays(array $days): string
    {
        if ($days === []) {
            return '';
        }

        $days = array_values(array_unique($days));
        sort($days);

        if ($days === range(0, 6)) {
            return '0-6';
        }

        $parts = [];
        $start = $days[0];
        $previous = $days[0];

        for ($index = 1, $count = count($days); $index < $count; $index++) {
            if ($days[$index] === $previous + 1) {
                $previous = $days[$index];

                continue;
            }

            $parts[] = $start === $previous
                ? (string) $start
                : $start.self::DAY_RANGE_SEPARATOR.$previous;
            $start = $days[$index];
            $previous = $days[$index];
        }

        $parts[] = $start === $previous
            ? (string) $start
            : $start.self::DAY_RANGE_SEPARATOR.$previous;

        return implode(self::VALUE_SEPARATOR, $parts);
    }

    /**
     * Bulk-upsert validated rows in chunked transactions, keyed on `israeli_id`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array{line: int, field: string, message: string}>  $errors
     * @return array{created: int, updated: int}
     */
    private function persist(array $rows, array &$errors): array
    {
        if ($rows === []) {
            return ['created' => 0, 'updated' => 0];
        }

        $roleIdByCode = Role::query()->pluck('id', 'code');
        $shiftIdByCode = Shift::query()->pluck('id', 'code');

        $created = 0;
        $updated = 0;

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            try {
                $result = $this->persistChunk($chunk, $roleIdByCode, $shiftIdByCode);

                $created += $result['created'];
                $updated += $result['updated'];
            } catch (Throwable $exception) {
                foreach ($chunk as $row) {
                    $errors[] = [
                        'line' => (int) $row['line'],
                        'field' => 'row',
                        'message' => 'Failed to import row: '.$exception->getMessage(),
                    ];
                }
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Persist a single chunk of validated rows in one transaction.
     *
     * @param  list<array<string, mixed>>  $chunk
     * @param  Collection<string, int>  $roleIdByCode
     * @param  Collection<string, int>  $shiftIdByCode
     * @return array{created: int, updated: int}
     */
    private function persistChunk(array $chunk, Collection $roleIdByCode, Collection $shiftIdByCode): array
    {
        return DB::transaction(function () use ($chunk, $roleIdByCode, $shiftIdByCode): array {
            $now = Carbon::now();

            /** @var list<string> $israeliIds */
            $israeliIds = array_column($chunk, 'israeli_id');

            $existingCount = Worker::query()
                ->whereIn('israeli_id', $israeliIds)
                ->count();

            $workerRows = array_map(static fn (array $row): array => [
                'full_name' => $row['full_name'],
                'israeli_id' => $row['israeli_id'],
                'role_id' => (int) $roleIdByCode[$row['role_code']],
                'is_active' => $row['is_active'],
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk);

            Worker::query()->upsert(
                $workerRows,
                ['israeli_id'],
                ['full_name', 'role_id', 'is_active', 'updated_at'],
            );

            $contractRows = array_map(static function (array $row) use ($now): array {
                /** @var array{hourly_cost: float|int|string, min_monthly_hours: int, max_monthly_hours: int} $contract */
                $contract = $row['contract'];

                return [
                    'worker_id' => $row['israeli_id'],
                    'hourly_cost' => $contract['hourly_cost'],
                    'min_monthly_hours' => $contract['min_monthly_hours'],
                    'max_monthly_hours' => $contract['max_monthly_hours'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }, $chunk);

            Contract::query()->upsert(
                $contractRows,
                ['worker_id'],
                ['hourly_cost', 'min_monthly_hours', 'max_monthly_hours', 'updated_at'],
            );

            $contractIdByWorkerId = Contract::query()
                ->whereIn('worker_id', $israeliIds)
                ->pluck('id', 'worker_id');

            $this->replaceAvailability($chunk, $contractIdByWorkerId, $shiftIdByCode);

            return [
                'created' => count($chunk) - $existingCount,
                'updated' => $existingCount,
            ];
        });
    }

    /**
     * Replace all availability rows (days and shifts) for the chunk's contracts.
     *
     * @param  list<array<string, mixed>>  $chunk
     * @param  Collection<string, int>  $contractIdByWorkerId
     * @param  Collection<string, int>  $shiftIdByCode
     */
    private function replaceAvailability(
        array $chunk,
        Collection $contractIdByWorkerId,
        Collection $shiftIdByCode,
    ): void {
        $contractIds = $contractIdByWorkerId->values()->all();

        ContractAvailability::query()->whereIn('contract_id', $contractIds)->delete();

        $availabilityRows = [];

        foreach ($chunk as $row) {
            $contractId = (int) $contractIdByWorkerId[$row['israeli_id']];

            /** @var list<array{day_of_week: int, shift_code: string}> $slots */
            $slots = $row['availability'];

            foreach ($slots as $slot) {
                $availabilityRows[] = [
                    'contract_id' => $contractId,
                    'day_of_week' => $slot['day_of_week'],
                    'shift_id' => (int) $shiftIdByCode[$slot['shift_code']],
                ];
            }
        }

        if ($availabilityRows !== []) {
            ContractAvailability::query()->insert($availabilityRows);
        }
    }

    /**
     * Parse the header row and map shift column indices to shift codes.
     *
     * @return array{
     *     map: array<int, array{label: string, code: string}>,
     *     errors: list<array{line: int, field: string, message: string}>
     * }
     */
    private function parseHeaderRow(string $path): array
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(
            SplFileObject::READ_CSV
            | SplFileObject::READ_AHEAD
            | SplFileObject::SKIP_EMPTY
            | SplFileObject::DROP_NEW_LINE,
        );

        $headerRow = $file->fgetcsv();

        if (! is_array($headerRow) || $headerRow === [null]) {
            return [
                'map' => [],
                'errors' => [[
                    'line' => 1,
                    'field' => 'header',
                    'message' => 'The CSV header row is missing or invalid.',
                ]],
            ];
        }

        $errors = [];
        $map = [];
        $knownLabels = $this->shiftCodeByColumnLabel();

        for ($index = self::SHIFT_COLUMN_OFFSET, $count = count($headerRow); $index < $count; $index++) {
            $label = trim((string) ($headerRow[$index] ?? ''));

            if ($label === '') {
                continue;
            }

            if (! isset($knownLabels[$label])) {
                $errors[] = [
                    'line' => 1,
                    'field' => $label,
                    'message' => "Unknown shift column \"{$label}\"; expected one of: "
                        .implode(', ', array_keys($knownLabels)).'.',
                ];

                continue;
            }

            $map[$index] = [
                'label' => $label,
                'code' => $knownLabels[$label],
            ];
        }

        if ($map === [] && $errors === []) {
            $errors[] = [
                'line' => 1,
                'field' => 'header',
                'message' => 'The CSV must include at least one shift availability column.',
            ];
        }

        return [
            'map' => $map,
            'errors' => $errors,
        ];
    }

    /**
     * Stream data rows from the CSV, skipping the header line.
     *
     * @return Generator<int, list<string|null>>
     */
    private function readDataRows(string $path): Generator
    {
        $file = new SplFileObject($path, 'r');
        $file->setFlags(
            SplFileObject::READ_CSV
            | SplFileObject::READ_AHEAD
            | SplFileObject::SKIP_EMPTY
            | SplFileObject::DROP_NEW_LINE,
        );

        foreach ($file as $index => $row) {
            if (! is_array($row) || $row === [null]) {
                continue;
            }

            $line = $index + 1;

            if ($line === 1) {
                continue;
            }

            /** @var list<string|null> $row */
            yield $line => $row;
        }
    }

    /**
     * Validate a single raw CSV row by column index.
     *
     * @param  list<string|null>  $row
     * @param  array<int, array{label: string, code: string}>  $shiftColumnMap
     * @return array{data: array<string, mixed>|null, errors: array<string, list<string>>}
     */
    private function validateRow(array $row, array $shiftColumnMap): array
    {
        $fields = $this->mapRow($row);

        $validator = Validator::make($fields, $this->rules(), $this->messages());

        /** @var array<string, list<string>> $messages */
        $messages = $validator->fails() ? $validator->errors()->messages() : [];

        $availabilityResult = $this->parseShiftColumns($row, $shiftColumnMap);

        if ($availabilityResult['errors'] !== []) {
            foreach ($availabilityResult['errors'] as $field => $fieldMessages) {
                $messages[$field] = array_merge($messages[$field] ?? [], $fieldMessages);
            }
        }

        if ($messages === [] && Worker::query()->whereKey((string) $fields['israeli_id'])->exists()) {
            try {
                $this->contractValidator->assertMaxHoursAllowed(
                    (string) $fields['israeli_id'],
                    (int) $fields['max_monthly_hours'],
                );
            } catch (WorkerContractException $exception) {
                $messages['max_monthly_hours'][] = $exception->getMessage();
            }
        }

        if ($messages !== []) {
            return ['data' => null, 'errors' => $messages];
        }

        return ['data' => $this->normalize($fields, $availabilityResult['slots']), 'errors' => []];
    }

    /**
     * Map the index-based row to named, pre-normalized fields for validation.
     *
     * @param  list<string|null>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $status = $this->cell($row, self::STATUS);
        $role = $this->cell($row, self::ROLE);

        return [
            'full_name' => $this->cell($row, self::FULL_NAME),
            'israeli_id' => $this->cell($row, self::ISRAELI_ID),
            'role' => $role === null ? null : strtolower($role),
            'status' => $status === null || $status === ''
                ? self::DEFAULT_STATUS
                : ucfirst(strtolower($status)),
            'hourly_cost' => $this->cell($row, self::HOURLY_COST),
            'min_monthly_hours' => $this->cell($row, self::MIN_MONTHLY_HOURS),
            'max_monthly_hours' => $this->cell($row, self::MAX_MONTHLY_HOURS),
        ];
    }

    /**
     * Build the validated, persistence-ready payload from valid fields.
     *
     * @param  array<string, mixed>  $fields
     * @param  list<array{day_of_week: int, shift_code: string}>  $slots
     * @return array<string, mixed>
     */
    private function normalize(array $fields, array $slots): array
    {
        return [
            'full_name' => trim((string) $fields['full_name']),
            'israeli_id' => (string) $fields['israeli_id'],
            'role_code' => RoleCode::codeByCsvLabel()[$fields['role']],
            'is_active' => $fields['status'] === self::STATUS_ACTIVE,
            'contract' => [
                'hourly_cost' => round((float) $fields['hourly_cost'], 2),
                'min_monthly_hours' => (int) $fields['min_monthly_hours'],
                'max_monthly_hours' => (int) $fields['max_monthly_hours'],
            ],
            'availability' => $slots,
        ];
    }

    /**
     * Validation rules, reusing the worker/contract rules used elsewhere.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'israeli_id' => ['required', 'string', new IsraeliId],
            'role' => ['required', Rule::in(array_keys(RoleCode::codeByCsvLabel()))],
            'status' => ['required', Rule::in([self::STATUS_ACTIVE, self::STATUS_INACTIVE])],
            'hourly_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'min_monthly_hours' => ['required', 'integer', 'min:0', 'max:744'],
            'max_monthly_hours' => ['required', 'integer', 'min:0', 'max:744', 'gte:min_monthly_hours'],
        ];
    }

    /**
     * Human-readable validation messages for the row error report.
     *
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'required' => 'The :attribute is required.',
            'role.in' => 'Unknown role; expected General Guard, Supervisor, or Screener.',
            'status.in' => 'Unknown status; expected Active or Inactive.',
            'max_monthly_hours.gte' => 'max_monthly_hours must be greater than or equal to min_monthly_hours.',
        ];
    }

    /**
     * Parse shift-column cells into day/shift availability pairs.
     *
     * @param  list<string|null>  $row
     * @param  array<int, array{label: string, code: string}>  $shiftColumnMap
     * @return array{
     *     slots: list<array{day_of_week: int, shift_code: string}>,
     *     errors: array<string, list<string>>
     * }
     */
    private function parseShiftColumns(array $row, array $shiftColumnMap): array
    {
        $slots = [];
        $seen = [];
        $errors = [];

        foreach ($shiftColumnMap as $columnIndex => $shiftColumn) {
            $expression = $this->cell($row, $columnIndex) ?? '';
            $field = $shiftColumn['label'];

            if ($expression === '') {
                continue;
            }

            $dayResult = $this->parseDayExpression($expression);

            if ($dayResult['errors'] !== []) {
                foreach ($dayResult['errors'] as $message) {
                    $errors[$field][] = $message;
                }

                continue;
            }

            foreach ($dayResult['days'] as $dayOfWeek) {
                $key = "{$dayOfWeek}:{$shiftColumn['code']}";

                if (isset($seen[$key])) {
                    $errors[$field][] = "Duplicate availability for day {$dayOfWeek}.";

                    continue;
                }

                $seen[$key] = true;
                $slots[] = [
                    'day_of_week' => $dayOfWeek,
                    'shift_code' => $shiftColumn['code'],
                ];
            }
        }

        if ($slots === [] && $errors === []) {
            $errors['availability'][] = 'At least one shift availability column must be set.';
        }

        return [
            'slots' => $slots,
            'errors' => $errors,
        ];
    }

    /**
     * Parse a cron-style day expression into day-of-week numbers.
     *
     * @return array{days: list<int>, errors: list<string>}
     */
    private function parseDayExpression(string $expression): array
    {
        $expression = trim($expression);

        if ($expression === '') {
            return ['days' => [], 'errors' => []];
        }

        $days = [];
        $errors = [];

        foreach (explode(self::VALUE_SEPARATOR, $expression) as $token) {
            $token = trim($token);

            if ($token === '') {
                $errors[] = 'Empty day token in expression.';

                continue;
            }

            if (str_contains($token, self::DAY_RANGE_SEPARATOR)) {
                $rangeParts = explode(self::DAY_RANGE_SEPARATOR, $token, 2);

                if (count($rangeParts) !== 2 || $rangeParts[1] === '') {
                    $errors[] = "Invalid day range \"{$token}\"; expected format like 1-5.";

                    continue;
                }

                if (! ctype_digit($rangeParts[0]) || ! ctype_digit($rangeParts[1])) {
                    $errors[] = "Invalid day range \"{$token}\"; day numbers must be 0-6.";

                    continue;
                }

                $start = (int) $rangeParts[0];
                $end = (int) $rangeParts[1];

                if ($start < 0 || $start > 6 || $end < 0 || $end > 6) {
                    $errors[] = "Day range \"{$token}\" must use day numbers 0 (Sunday) through 6 (Saturday).";

                    continue;
                }

                if ($start > $end) {
                    $errors[] = "Day range \"{$token}\" must have start <= end.";

                    continue;
                }

                for ($day = $start; $day <= $end; $day++) {
                    $days[] = $day;
                }

                continue;
            }

            if (! ctype_digit($token)) {
                $errors[] = "Invalid day token \"{$token}\"; expected a number 0-6 or a range like 1-5.";

                continue;
            }

            $day = (int) $token;

            if ($day < 0 || $day > 6) {
                $errors[] = "Invalid day token \"{$token}\"; day numbers must be 0 (Sunday) through 6 (Saturday).";

                continue;
            }

            $days[] = $day;
        }

        $days = array_values(array_unique($days));
        sort($days);

        return [
            'days' => $days,
            'errors' => $errors,
        ];
    }

    /**
     * Read and trim a single cell by column index.
     *
     * @param  list<string|null>  $row
     */
    private function cell(array $row, int $index): ?string
    {
        $value = $row[$index] ?? null;

        if ($value === null) {
            return null;
        }

        return trim($value);
    }

    /**
     * Get the ordered shifts.
     *
     * @return Collection<int, Shift>
     */
    private function orderedShifts(): Collection
    {
        if ($this->orderedShifts === null) {
            $this->orderedShifts = Shift::query()->orderBy('start_time')->get();
        }

        return $this->orderedShifts;
    }

    /**
     * Get the shift code by column label.
     *
     * @return array<string, string>
     */
    private function shiftCodeByColumnLabel(): array
    {
        if ($this->shiftCodeByColumnLabel === null) {
            $this->shiftCodeByColumnLabel = $this->orderedShifts()
                ->mapWithKeys(fn (Shift $shift): array => [self::shiftColumnLabel($shift) => $shift->code])
                ->all();
        }

        return $this->shiftCodeByColumnLabel;
    }
}
