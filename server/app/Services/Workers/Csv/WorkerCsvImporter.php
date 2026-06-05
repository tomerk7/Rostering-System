<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use App\Rules\IsraeliId;
use App\Services\Workers\WorkerService;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use SplFileObject;
use Throwable;

final class WorkerCsvImporter
{
    /**
     * Rows persisted per transaction. Bounds memory and lock duration while
     * keeping per-row failures isolated to a rolled-back savepoint.
     */
    private const int CHUNK_SIZE = 500;

    public function __construct(
        private readonly WorkerService $workerService,
    ) {
    }

    /**
     * Import a worker CSV: stream, validate, dedupe, and upsert.
     *
     * Each row is validated independently and matched on `israeli_id` (the
     * upsert key) — existing workers are updated in place, new ones created.
     * Writes run in chunked transactions; a row that fails to persist is rolled
     * back to its savepoint and reported without aborting the rest.
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

            if ($result['errors'] !== []) {
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
     * Upsert validated rows in chunked transactions, keyed on `israeli_id`.
     *
     * Role/shift code-to-id lookups are loaded once. Each chunk runs in one
     * transaction; each row is written inside a nested savepoint so a single
     * failing row is rolled back and collected as an error while the chunk and
     * subsequent rows continue.
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
            $israeliIds = array_column($chunk, 'israeli_id');

            $existingWorkers = Worker::query()
                ->whereIn('israeli_id', $israeliIds)
                ->get()
                ->keyBy('israeli_id');

            $result = DB::transaction(function () use ($chunk, $existingWorkers, $roleIdByCode, $shiftIdByCode, &$errors): array {
                $created = 0;
                $updated = 0;

                foreach ($chunk as $row) {
                    /** @var string $israeliId */
                    $israeliId = $row['israeli_id'];
                    $worker = $existingWorkers->get($israeliId);

                    try {
                        $payload = $this->toWorkerData($row, $roleIdByCode, $shiftIdByCode);

                        if ($worker instanceof Worker) {
                            $this->workerService->update($worker, $payload);
                            $updated++;
                        } else {
                            $this->workerService->create($payload);
                            $created++;
                        }
                    } catch (Throwable $exception) {
                        $errors[] = [
                            'line' => (int) $row['line'],
                            'field' => 'row',
                            'message' => 'Failed to import row: ' . $exception->getMessage(),
                        ];
                    }
                }

                return ['created' => $created, 'updated' => $updated];
            });

            $created += $result['created'];
            $updated += $result['updated'];
        }

        return ['created' => $created, 'updated' => $updated];
    }

    /**
     * Reshape a validated row into the WorkerService create/update payload.
     *
     * Maps the role code and shift codes to their ids using the preloaded
     * lookups; availability days are already 0–6 integers.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, int>  $roleIdByCode
     * @param  Collection<string, int>  $shiftIdByCode
     * @return array<string, mixed>
     */
    private function toWorkerData(array $row, Collection $roleIdByCode, Collection $shiftIdByCode): array
    {
        /** @var array{days: list<int>, shift_codes: list<string>} $availability */
        $availability = $row['availability'];

        $shiftIds = array_map(
            static fn (string $code): int => (int) $shiftIdByCode[$code],
            $availability['shift_codes'],
        );

        return [
            'full_name' => $row['full_name'],
            'israeli_id' => $row['israeli_id'],
            'role_id' => (int) $roleIdByCode[$row['role_code']],
            'is_active' => $row['is_active'],
            'contract' => $row['contract'],
            'availability' => [
                'days' => $availability['days'],
                'shifts' => $shiftIds,
            ],
        ];
    }

    /**
     * Stream data rows from the CSV, skipping the header line.
     *
     * Yields the 1-based source file line number (header = line 1) keyed to the
     * raw column array. Reading is line-by-line so memory stays flat.
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

        if ($validator->fails()) {
            /** @var array<string, list<string>> $messages */
            $messages = $validator->errors()->messages();

            return ['data' => null, 'errors' => $messages];
        }

        return ['data' => $this->normalize($fields), 'errors' => []];
    }

    /**
     * Map the index-based row to named, pre-normalized fields for validation.
     *
     * @param  list<string|null>  $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row): array
    {
        $status = $this->cell($row, WorkerCsvFormat::STATUS);
        $role = $this->cell($row, WorkerCsvFormat::ROLE);

        return [
            'full_name' => $this->cell($row, WorkerCsvFormat::FULL_NAME),
            'israeli_id' => $this->cell($row, WorkerCsvFormat::ISRAELI_ID),
            'role' => $role === null ? null : strtolower($role),
            'status' => $status === null || $status === ''
                ? WorkerCsvFormat::DEFAULT_STATUS
                : ucfirst(strtolower($status)),
            'hourly_cost' => $this->cell($row, WorkerCsvFormat::HOURLY_COST),
            'min_monthly_hours' => $this->cell($row, WorkerCsvFormat::MIN_MONTHLY_HOURS),
            'max_monthly_hours' => $this->cell($row, WorkerCsvFormat::MAX_MONTHLY_HOURS),
            'available_days' => $this->tokens($this->cell($row, WorkerCsvFormat::AVAILABLE_DAYS), strtolower(...)),
            'available_shifts' => $this->tokens($this->cell($row, WorkerCsvFormat::AVAILABLE_SHIFTS), strtoupper(...)),
        ];
    }

    /**
     * Build the validated, persistence-ready payload from valid fields.
     *
     * Tokens are mapped to codes/numbers; resolving them to role/shift IDs is
     * the persistence step's responsibility.
     *
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function normalize(array $fields): array
    {
        /** @var list<string> $days */
        $days = $fields['available_days'];
    
        /** @var list<string> $shifts */
        $shifts = $fields['available_shifts'];
    
        $dayNumbers = [];
    
        foreach ($days as $day) {
            $dayNumbers[WorkerCsvFormat::DAY_OF_WEEK_BY_TOKEN[$day]] = true;
        }
    
        $dayNumbers = array_keys($dayNumbers);
    
        sort($dayNumbers);
    
        $shiftCodes = array_values(array_unique($shifts));
    
        sort($shiftCodes);
    
        return [
            'full_name' => trim((string) $fields['full_name']),
            'israeli_id' => (string) $fields['israeli_id'],
            'role_code' => WorkerCsvFormat::ROLE_CODE_BY_LABEL[$fields['role']],
            'is_active' => $fields['status'] === WorkerCsvFormat::STATUS_ACTIVE,
            'contract' => [
                'hourly_cost' => round((float) $fields['hourly_cost'], 2),
                'min_monthly_hours' => (int) $fields['min_monthly_hours'],
                'max_monthly_hours' => (int) $fields['max_monthly_hours'],
            ],
            'availability' => [
                'days' => $dayNumbers,
                'shift_codes' => $shiftCodes,
            ],
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
            'role' => ['required', Rule::in(array_keys(WorkerCsvFormat::ROLE_CODE_BY_LABEL))],
            'status' => ['required', Rule::in([WorkerCsvFormat::STATUS_ACTIVE, WorkerCsvFormat::STATUS_INACTIVE])],
            'hourly_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'min_monthly_hours' => ['required', 'integer', 'min:0', 'max:744'],
            'max_monthly_hours' => ['required', 'integer', 'min:0', 'max:744', 'gte:min_monthly_hours'],
            'available_days' => ['required', 'array', 'min:1', 'max:7'],
            'available_days.*' => ['required', 'distinct', Rule::in(array_keys(WorkerCsvFormat::DAY_OF_WEEK_BY_TOKEN))],
            'available_shifts' => ['required', 'array', 'min:1', 'max:3'],
            'available_shifts.*' => ['required', 'distinct', Rule::in(WorkerCsvFormat::SHIFT_CODES)],
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
            'available_days.*.in' => 'Unknown day token; expected Sun, Mon, Tue, Wed, Thu, Fri, or Sat.',
            'available_days.*.distinct' => 'Duplicate day token.',
            'available_shifts.*.in' => 'Unknown shift token; expected A, B, or C.',
            'available_shifts.*.distinct' => 'Duplicate shift token.',
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
    
        foreach (explode(WorkerCsvFormat::VALUE_SEPARATOR, $value) as $token) {
            $tokens[] = $normalizer(trim($token));
        }
    
        return $tokens;
    }
}
