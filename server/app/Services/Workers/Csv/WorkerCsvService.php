<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Jobs\ExportWorkersJob;
use App\Jobs\ImportWorkersJob;
use App\Models\Contract;
use App\Models\ContractAvailability;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use App\Rules\IsraeliId;
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
 * Columns are matched by position (zero-based index), not header name; the
 * header row is documentation only. See prompts/docs/csv-schema.md.
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
    public const int AVAILABILITY = 7;

    /**
     * Fixed column order, written verbatim as the export header row.
     *
     * @var list<string>
     */
    public const array HEADERS = [
        'full_name',
        'israeli_id',
        'role',
        'status',
        'hourly_cost',
        'min_monthly_hours',
        'max_monthly_hours',
        'availability',
    ];

    /**
     * In-cell separator for multi-value columns (days, shifts). A pipe is used
     * so commas never trigger Excel quoting.
     */
    public const string VALUE_SEPARATOR = '|';

    /**
     * Separator between day groups in the availability column.
     */
    public const string DAY_GROUP_SEPARATOR = ';';

    public const string STATUS_ACTIVE = 'Active';
    public const string STATUS_INACTIVE = 'Inactive';
    public const string DEFAULT_STATUS = self::STATUS_ACTIVE;

    /**
     * CSV role label (lowercased) to roles.code.
     *
     * @var array<string, string>
     */
    public const array ROLE_CODE_BY_LABEL = [
        'general guard' => 'general_guard',
        'supervisor' => 'supervisor',
        'screener' => 'screener',
    ];

    /**
     * roles.code to CSV role label, used on export.
     *
     * @var array<string, string>
     */
    public const array ROLE_LABEL_BY_CODE = [
        'general_guard' => 'General Guard',
        'supervisor' => 'Supervisor',
        'screener' => 'Screener',
    ];

    /**
     * CSV day token (lowercased) to day_of_week (0 = Sunday .. 6 = Saturday).
     *
     * @var array<string, int>
     */
    public const array DAY_OF_WEEK_BY_TOKEN = [
        'sun' => 0,
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
    ];

    /**
     * day_of_week (0..6) to CSV day token, used on export.
     *
     * @var array<int, string>
     */
    public const array DAY_TOKEN_BY_NUMBER = [
        0 => 'Sun',
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
    ];

    /**
     * Allowed shift codes.
     *
     * @var list<string>
     */
    public const array SHIFT_CODES = ['A', 'B', 'C'];

    private const string IMPORT_STORAGE_DIR = 'worker-imports';

    private const string EXPORT_STORAGE_DIR = 'worker-exports';

    /**
     * Rows persisted per transaction. Bounds memory and lock duration while
     * keeping each chunk's bulk writes to a handful of queries.
     */
    private const int CHUNK_SIZE = 1000;

    /**
     * Store an uploaded CSV and queue it for import.
     *
     * @param UploadedFile $file
     * @return string
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
     *
     * @param string $importId
     * @param string $storedPath
     * @return void
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
     *
     * @param string $storedPath
     * @return void
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
     *
     * @return void
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
     *
     * @param string $importId
     * @param string $storedPath
     * @param string $message
     * @return void
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
     * @param string $importId
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
     *
     * @return string
     */
    public function queueExport(): string
    {
        $exportId = (string) Str::uuid();
        $storedPath = self::EXPORT_STORAGE_DIR . "/{$exportId}.csv";

        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'processing',
        ], now()->addHour());

        ExportWorkersJob::dispatch($exportId, $storedPath);

        return $exportId;
    }

    /**
     * Process a queued worker CSV export.
     *
     * @param string $exportId
     * @param string $storedPath
     * @return void
     */
    public function processExport(string $exportId, string $storedPath): void
    {
        Storage::disk('local')->makeDirectory(self::EXPORT_STORAGE_DIR);

        $handle = fopen(Storage::disk('local')->path($storedPath), 'w');

        if (!$handle) {
            throw new RuntimeException("Unable to open export file for writing: {$storedPath}");
        }

        try {
            fputcsv($handle, self::HEADERS);

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
            'filename' => 'workers-' . now()->format('Y-m-d') . '.csv',
        ], now()->addHour());
    }

    /**
     * Record a failed worker CSV export and remove the stored file.
     *
     * @param string $exportId
     * @param string $storedPath
     * @param string $message
     * @return void
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
     *
     * @param string $exportId
     * @return StreamedResponse
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

        /** @var list<array<string, mixed>> $rows validated, persistence-ready rows */
        $rows = [];

        /** @var array<string, int> $seenIds line number where each israeli_id was first seen */
        $seenIds = [];

        foreach ($this->readRows($path) as $line => $row) {
            $total++;

            $result = $this->validateRow($row);

            // Record validation errors and skip the invalid row.
            if (!empty($result['errors'])) {
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
     *
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
     *
     * @param string $importId
     * @return string
     */
    private function importCacheKey(string $importId): string
    {
        return "worker-import:{$importId}";
    }

    /**
     * Build a cache key for a queued worker CSV export.
     *
     * @param string $exportId
     * @return string
     */
    private function exportCacheKey(string $exportId): string
    {
        return "worker-export:{$exportId}";
    }

    /**
     * Build a single CSV row for a worker in fixed column order.
     *
     * @return array<int, string>
     */
    private function toRow(Worker $worker): array
    {
        $contract = $worker->contract;

        $row = [
            self::FULL_NAME => $worker->full_name,
            self::ISRAELI_ID => $worker->israeli_id,
            self::ROLE => self::ROLE_LABEL_BY_CODE[$worker->role->code] ?? $worker->role->code,
            self::STATUS => $worker->is_active
                ? self::STATUS_ACTIVE
                : self::STATUS_INACTIVE,
            self::HOURLY_COST => (string) $contract?->hourly_cost,
            self::MIN_MONTHLY_HOURS => (string) $contract?->min_monthly_hours,
            self::MAX_MONTHLY_HOURS => (string) $contract?->max_monthly_hours,
            self::AVAILABILITY => $this->availability($contract),
        ];

        ksort($row);

        return $row;
    }

    /**
     * Serialize per-weekday shift availability, e.g. Sun:C;Mon:A|B;Wed:C.
     *
     * @param object|null $contract
     * @return string
     */
    private function availability(?object $contract): string
    {
        if ($contract === null) {
            return '';
        }

        /** @var array<string, list<string>> $byDay */
        $byDay = [];

        foreach ($contract->availability as $slot) {
            $dayToken = self::DAY_TOKEN_BY_NUMBER[(int) $slot->day_of_week];
            $byDay[$dayToken][] = (string) $slot->shift->code;
        }

        $groups = [];

        foreach (self::DAY_TOKEN_BY_NUMBER as $dayToken) {
            if (! isset($byDay[$dayToken])) {
                continue;
            }

            $codes = $byDay[$dayToken];
            sort($codes);
            $groups[] = $dayToken . ':' . implode(self::VALUE_SEPARATOR, $codes);
        }

        return implode(self::DAY_GROUP_SEPARATOR, $groups);
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
                        'message' => 'Failed to import row: ' . $exception->getMessage(),
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

            $workerIdByIsraeliId = Worker::query()
                ->whereIn('israeli_id', $israeliIds)
                ->pluck('id', 'israeli_id');

            $contractRows = array_map(static function (array $row) use ($workerIdByIsraeliId, $now): array {
                /** @var array{hourly_cost: float|int|string, min_monthly_hours: int, max_monthly_hours: int} $contract */
                $contract = $row['contract'];

                return [
                    'worker_id' => (int) $workerIdByIsraeliId[$row['israeli_id']],
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
                ->whereIn('worker_id', $workerIdByIsraeliId->values())
                ->pluck('id', 'worker_id');

            $this->replaceAvailability($chunk, $workerIdByIsraeliId, $contractIdByWorkerId, $shiftIdByCode);

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
     * @param  Collection<string, int>  $workerIdByIsraeliId
     * @param  Collection<int, int>  $contractIdByWorkerId
     * @param  Collection<string, int>  $shiftIdByCode
     */
    private function replaceAvailability(
        array $chunk,
        Collection $workerIdByIsraeliId,
        Collection $contractIdByWorkerId,
        Collection $shiftIdByCode,
    ): void {
        $contractIds = $contractIdByWorkerId->values()->all();

        ContractAvailability::query()->whereIn('contract_id', $contractIds)->delete();

        $availabilityRows = [];

        foreach ($chunk as $row) {
            $workerId = (int) $workerIdByIsraeliId[$row['israeli_id']];
            $contractId = (int) $contractIdByWorkerId[$workerId];

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
     * Stream data rows from the CSV, skipping the header line.
     *
     * @return Generator<int, list<string|null>>
     */
    private function readRows(string $path): Generator
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

            // Skipping the header line.
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
     * @return array{data: array<string, mixed>|null, errors: array<string, list<string>>}
     */
    private function validateRow(array $row): array
    {
        $fields = $this->mapRow($row);

        $validator = Validator::make($fields, $this->rules(), $this->messages());

        /** @var array<string, list<string>> $messages */
        $messages = $validator->fails() ? $validator->errors()->messages() : [];

        $availabilityResult = $this->parseAvailability((string) ($fields['availability'] ?? ''));

        if ($availabilityResult['errors'] !== []) {
            foreach ($availabilityResult['errors'] as $field => $fieldMessages) {
                $messages[$field] = array_merge($messages[$field] ?? [], $fieldMessages);
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
            'availability' => $this->cell($row, self::AVAILABILITY),
        ];
    }

    /**
     * Build the validated, persistence-ready payload from valid fields.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    /**
     * @param  list<array{day_of_week: int, shift_code: string}>  $slots
     * @return array<string, mixed>
     */
    private function normalize(array $fields, array $slots): array
    {
        return [
            'full_name' => trim((string) $fields['full_name']),
            'israeli_id' => (string) $fields['israeli_id'],
            'role_code' => self::ROLE_CODE_BY_LABEL[$fields['role']],
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
            'israeli_id' => ['required', 'string', 'size:9', new IsraeliId],
            'role' => ['required', Rule::in(array_keys(self::ROLE_CODE_BY_LABEL))],
            'status' => ['required', Rule::in([self::STATUS_ACTIVE, self::STATUS_INACTIVE])],
            'hourly_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'min_monthly_hours' => ['required', 'integer', 'min:0', 'max:744'],
            'max_monthly_hours' => ['required', 'integer', 'min:0', 'max:744', 'gte:min_monthly_hours'],
            'availability' => ['required', 'string', 'max:255'],
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
            'israeli_id.size' => 'The israeli_id must be exactly 9 digits.',
            'role.in' => 'Unknown role; expected General Guard, Supervisor, or Screener.',
            'status.in' => 'Unknown status; expected Active or Inactive.',
            'max_monthly_hours.gte' => 'max_monthly_hours must be greater than or equal to min_monthly_hours.',
            'availability.required' => 'The availability is required.',
        ];
    }

    /**
     * Parse the combined availability column into day/shift pairs.
     *
     * @return array{
     *     slots: list<array{day_of_week: int, shift_code: string}>,
     *     errors: array<string, list<string>>
     * }
     */
    private function parseAvailability(string $value): array
    {
        $value = trim($value);

        if ($value === '') {
            return [
                'slots' => [],
                'errors' => ['availability' => ['The availability is required.']],
            ];
        }

        $slots = [];
        $seen = [];
        $errors = [];

        foreach (explode(self::DAY_GROUP_SEPARATOR, $value) as $group) {
            $group = trim($group);

            if ($group === '') {
                $errors['availability'][] = 'Empty day group in availability.';

                continue;
            }

            $parts = explode(':', $group, 2);

            if (count($parts) !== 2) {
                $errors['availability'][] = "Invalid availability group \"{$group}\"; expected Day:Shift|Shift.";

                continue;
            }

            $dayToken = strtolower(trim($parts[0]));
            $shiftPart = trim($parts[1]);

            if ($shiftPart === '') {
                $errors['availability'][] = "Day group \"{$group}\" must list at least one shift.";

                continue;
            }

            if (! isset(self::DAY_OF_WEEK_BY_TOKEN[$dayToken])) {
                $errors['availability'][] = "Unknown day token \"{$parts[0]}\"; expected Sun, Mon, Tue, Wed, Thu, Fri, or Sat.";

                continue;
            }

            $dayOfWeek = self::DAY_OF_WEEK_BY_TOKEN[$dayToken];
            $shiftCodes = $this->tokens($shiftPart, strtoupper(...));

            foreach ($shiftCodes as $shiftCode) {
                if (! in_array($shiftCode, self::SHIFT_CODES, true)) {
                    $errors['availability'][] = "Unknown shift token \"{$shiftCode}\"; expected A, B, or C.";

                    continue;
                }

                $key = "{$dayOfWeek}:{$shiftCode}";

                if (isset($seen[$key])) {
                    $errors['availability'][] = "Duplicate availability for {$parts[0]} shift {$shiftCode}.";

                    continue;
                }

                $seen[$key] = true;
                $slots[] = [
                    'day_of_week' => $dayOfWeek,
                    'shift_code' => $shiftCode,
                ];
            }
        }

        if ($slots === [] && $errors === []) {
            $errors['availability'][] = 'The availability is required.';
        }

        return [
            'slots' => $slots,
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
     * Split a pipe-separated cell into trimmed, case-normalized tokens.
     *
     * @param  callable(string): string  $normalizer
     * @return list<string>
     */
    private function tokens(?string $value, callable $normalizer): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        $tokens = [];

        // Trim and normalize each day or shift value.
        foreach (explode(self::VALUE_SEPARATOR, $value) as $token) {
            $tokens[] = $normalizer(trim($token));
        }

        return $tokens;
    }
}
