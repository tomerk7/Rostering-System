<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Enums\RoleCode;
use App\Exceptions\Workers\WorkerContractException;
use App\Models\Contract;
use App\Models\ContractAvailability;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use App\Rules\IsraeliId;
use App\Services\Rostering\RosterReportService;
use App\Services\Workers\WorkerContractValidator;
use Generator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use SplFileObject;
use Throwable;

/**
 * Parse, validate, and persist worker CSV imports.
 */
final class WorkerCsvImporter
{
    /**
     * Rows persisted per transaction. Bounds memory and lock duration while
     * keeping each chunk's bulk writes to a handful of queries.
     */
    private const int CHUNK_SIZE = 1000;

    public function __construct(
        private readonly WorkerContractValidator $contractValidator,
        private readonly WorkerCsvSchema $schema,
        private readonly RosterReportService $reportService,
    ) {}

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
            /** @var list<string> $importedWorkerIds */
            $importedWorkerIds = array_column($rows, 'israeli_id');

            $this->reportService->refreshReportsForWorkers($importedWorkerIds);
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

            $existingCount = Worker::withTrashed()
                ->whereIn('israeli_id', $israeliIds)
                ->count();

            Worker::onlyTrashed()
                ->whereIn('israeli_id', $israeliIds)
                ->restore();

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
    private function replaceAvailability(array $chunk, Collection $contractIdByWorkerId, Collection $shiftIdByCode): void
    {
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
     * @param string $path
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
        $knownLabels = $this->schema->shiftCodeByColumnLabel();

        for ($index = WorkerCsvSchema::SHIFT_COLUMN_OFFSET, $count = count($headerRow); $index < $count; $index++) {
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
     * @param string $path
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

        if ($messages === []) {
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
        $status = $this->cell($row, WorkerCsvSchema::STATUS);
        $role = $this->cell($row, WorkerCsvSchema::ROLE);

        return [
            'full_name' => $this->cell($row, WorkerCsvSchema::FULL_NAME),
            'israeli_id' => $this->cell($row, WorkerCsvSchema::ISRAELI_ID),
            'role' => $role === null ? null : strtolower($role),
            'status' => $status === null || $status === ''
                ? WorkerCsvSchema::DEFAULT_STATUS
                : ucfirst(strtolower($status)),
            'hourly_cost' => $this->cell($row, WorkerCsvSchema::HOURLY_COST),
            'min_monthly_hours' => $this->cell($row, WorkerCsvSchema::MIN_MONTHLY_HOURS),
            'max_monthly_hours' => $this->cell($row, WorkerCsvSchema::MAX_MONTHLY_HOURS),
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
            'is_active' => $fields['status'] === WorkerCsvSchema::STATUS_ACTIVE,
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
            'status' => ['required', Rule::in([WorkerCsvSchema::STATUS_ACTIVE, WorkerCsvSchema::STATUS_INACTIVE])],
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

            foreach ($dayResult['days'] as $csvDay) {
                $dayOfWeek = $csvDay - 1;
                $key = "{$dayOfWeek}:{$shiftColumn['code']}";

                if (isset($seen[$key])) {
                    $errors[$field][] = "Duplicate availability for day {$csvDay}.";

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

        foreach (explode(WorkerCsvSchema::VALUE_SEPARATOR, $expression) as $token) {
            $token = trim($token);

            if ($token === '') {
                $errors[] = 'Empty day token in expression.';

                continue;
            }

            if (str_contains($token, WorkerCsvSchema::DAY_RANGE_SEPARATOR)) {
                $rangeParts = explode(WorkerCsvSchema::DAY_RANGE_SEPARATOR, $token, 2);

                if (count($rangeParts) !== 2 || $rangeParts[1] === '') {
                    $errors[] = "Invalid day range \"{$token}\"; expected format like 1-5.";

                    continue;
                }

                if (! ctype_digit($rangeParts[0]) || ! ctype_digit($rangeParts[1])) {
                    $errors[] = "Invalid day range \"{$token}\"; day numbers must be 1-7.";

                    continue;
                }

                $start = (int) $rangeParts[0];
                $end = (int) $rangeParts[1];

                if ($start < 1 || $start > 7 || $end < 1 || $end > 7) {
                    $errors[] = "Day range \"{$token}\" must use day numbers 1 (Sunday) through 7 (Saturday).";

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
                $errors[] = "Invalid day token \"{$token}\"; expected a number 1-7 or a range like 2-6.";

                continue;
            }

            $day = (int) $token;

            if ($day < 1 || $day > 7) {
                $errors[] = "Invalid day token \"{$token}\"; day numbers must be 1 (Sunday) through 7 (Saturday).";

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
}
