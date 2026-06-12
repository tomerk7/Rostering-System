<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\ContractAvailability;
use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use App\Services\Workers\Csv\WorkerCsvService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class WorkerApiTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;

    private Role $supervisorRole;

    private Shift $morningShift;

    private Shift $dayShift;

    private Shift $eveningShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        Sanctum::actingAs(User::factory()->create());

        $this->role = Role::query()->where('code', 'general_guard')->firstOrFail();
        $this->supervisorRole = Role::query()->where('code', 'supervisor')->firstOrFail();
        $this->morningShift = Shift::query()->where('code', 'A')->firstOrFail();
        $this->dayShift = Shift::query()->where('code', 'B')->firstOrFail();
        $this->eveningShift = Shift::query()->where('code', 'C')->firstOrFail();
    }

    public function test_workers_can_be_listed_with_search_and_filters(): void
    {
        $worker = Worker::factory()->create([
            'full_name' => 'Dana Cohen',
            'israeli_id' => $this->validIsraeliId(12345678),
            'role_id' => $this->role->id,
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1], [$this->morningShift->id])
            ->create();

        Worker::factory()
            ->inactive()
            ->create([
                'full_name' => 'Inactive Worker',
                'israeli_id' => $this->validIsraeliId(22345678),
            ]);

        $response = $this->getJson('/api/workers?search=Dana&role_code=general_guard&is_active=1');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.full_name', 'Dana Cohen')
            ->assertJsonCount(2, 'data.0.contract.availability')
            ->assertJsonPath('data.0.contract.availability.0.day_of_week', 0)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_worker_can_be_created_with_contract_and_availability(): void
    {
        $payload = $this->workerPayload([
            'full_name' => 'Created Worker',
            'israeli_id' => $this->validIsraeliId(32345678),
        ]);

        $response = $this->postJson('/api/workers', $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.full_name', 'Created Worker')
            ->assertJsonPath('data.role.id', $this->role->id)
            ->assertJsonPath('data.contract.min_monthly_hours', 120)
            ->assertJsonCount(6, 'data.contract.availability');

        $worker = Worker::query()->where('israeli_id', $payload['israeli_id'])->firstOrFail();

        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->id,
            'min_monthly_hours' => 120,
            'max_monthly_hours' => 180,
        ]);
        $this->assertDatabaseCount('contract_availability', 6);
    }

    public function test_worker_can_be_shown_and_updated_with_replaced_availability(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(42345678),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 50,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 120,
            ]);

        $this->getJson("/api/workers/{$worker->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $worker->id)
            ->assertJsonPath('data.contract.availability.0.shift.id', $this->morningShift->id);

        $payload = $this->workerPayload([
            'full_name' => 'Updated Worker',
            'israeli_id' => $worker->israeli_id,
            'availability' => $this->availabilityPairs([4, 5], [$this->dayShift->id]),
        ]);

        $this->putJson("/api/workers/{$worker->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Updated Worker')
            ->assertJsonCount(2, 'data.contract.availability')
            ->assertJsonPath('data.contract.availability.0.shift.id', $this->dayShift->id);

        $contract = $worker->contract()->firstOrFail();

        $this->assertDatabaseMissing('contract_availability', [
            'contract_id' => $contract->id,
            'day_of_week' => 0,
            'shift_id' => $this->morningShift->id,
        ]);
        $this->assertDatabaseHas('contract_availability', [
            'contract_id' => $contract->id,
            'day_of_week' => 4,
            'shift_id' => $this->dayShift->id,
        ]);
    }

    public function test_worker_validation_errors_are_returned_by_the_api(): void
    {
        $response = $this->postJson('/api/workers', [
            'full_name' => '',
            'israeli_id' => '123',
            'role_id' => 999,
            'is_active' => true,
            'contract' => [
                'hourly_cost' => -1,
                'min_monthly_hours' => 200,
                'max_monthly_hours' => 100,
            ],
            'availability' => [],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'full_name',
                'israeli_id',
                'role_id',
                'contract.hourly_cost',
                'contract.max_monthly_hours',
                'availability',
            ]);

        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_worker_availability_validation_rejects_unknown_shift_ids(): void
    {
        $payload = $this->workerPayload([
            'availability' => [
                ['day_of_week' => 1, 'shift_id' => 999],
            ],
        ]);

        $response = $this->postJson('/api/workers', $payload);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'availability.0.shift_id',
            ]);

        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_worker_save_rolls_back_when_nested_availability_write_fails(): void
    {
        ContractAvailability::creating(static function (): void {
            throw new RuntimeException('Forced availability failure.');
        });

        try {
            $response = $this->postJson('/api/workers', $this->workerPayload([
                'full_name' => 'Rollback Worker',
                'israeli_id' => $this->validIsraeliId(52345678),
            ]));

            $response->assertStatus(500);
        } finally {
            ContractAvailability::flushEventListeners();
        }

        $this->assertDatabaseMissing('workers', [
            'full_name' => 'Rollback Worker',
        ]);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_availability', 0);
    }

    public function test_worker_reference_data_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/workers/reference-data')
            ->assertUnauthorized();
    }

    public function test_worker_reference_data_returns_roles_and_shifts(): void
    {
        $response = $this->getJson('/api/workers/reference-data');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Reference data retrieved successfully.')
            ->assertJsonPath('data.roles.0.code', 'general_guard')
            ->assertJsonPath('data.roles.0.name', 'General Guard')
            ->assertJsonPath('data.shifts.0.code', 'A')
            ->assertJsonPath('data.shifts.0.label', 'morning')
            ->assertJsonCount(3, 'data.roles')
            ->assertJsonCount(3, 'data.shifts')
            ->assertJsonCount(9, 'data.shift_role_requirements')
            ->assertJsonStructure([
                'data' => [
                    'roles' => [['id', 'code', 'name']],
                    'shifts' => [['id', 'code', 'label', 'start_time', 'end_time', 'duration_hours']],
                    'shift_role_requirements' => [[
                        'shift_id',
                        'role_id',
                        'required_count',
                        'role' => ['id', 'code', 'name'],
                    ]],
                ],
            ]);
    }

    public function test_worker_reference_data_returns_requirements_ordered_by_shift_and_role(): void
    {
        $requirements = $this->getJson('/api/workers/reference-data')
            ->assertOk()
            ->json('data.shift_role_requirements');

        $shiftIds = array_column($requirements, 'shift_id');
        self::assertSame($shiftIds, collect($shiftIds)->sort()->values()->all());

        $groupedByShift = collect($requirements)->groupBy('shift_id');

        foreach ($groupedByShift as $shiftRequirements) {
            $roleIds = $shiftRequirements->pluck('role_id')->all();
            self::assertSame($roleIds, collect($roleIds)->sort()->values()->all());
        }
    }

    public function test_worker_import_sample_can_be_downloaded(): void
    {
        $expected = file_get_contents(database_path('data/workers-sample.csv'));

        $response = $this->get('/api/workers/import/sample');

        $response
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload('workers-sample.csv');

        self::assertSame($expected, $response->streamedContent());
    }

    public function test_worker_import_creates_workers_with_contract_and_availability(): void
    {
        $response = $this->importCsv([
            $this->csvRow(
                fullName: 'Created Worker',
                israeliId: $this->validIsraeliId(72345678),
                role: 'General Guard',
                status: 'Active',
                hourlyCost: '50.25',
                minMonthlyHours: '80',
                maxMonthlyHours: '160',
                availability: 'Sun:A|B;Tue:A|B;Thu:A|B',
            ),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.imported', 1)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('errors', []);

        $worker = Worker::query()
            ->where('israeli_id', $this->validIsraeliId(72345678))
            ->firstOrFail();

        self::assertSame('Created Worker', $worker->full_name);
        self::assertSame($this->role->id, $worker->role_id);
        self::assertTrue($worker->is_active);

        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->id,
            'hourly_cost' => '50.25',
            'min_monthly_hours' => 80,
            'max_monthly_hours' => 160,
        ]);

        $this->assertAvailability($worker, [0, 2, 4], [$this->morningShift->id, $this->dayShift->id]);
    }

    public function test_worker_import_updates_existing_worker_and_replaces_availability(): void
    {
        $israeliId = $this->validIsraeliId(82345678);
        $worker = Worker::factory()->create([
            'full_name' => 'Original Worker',
            'israeli_id' => $israeliId,
            'role_id' => $this->role->id,
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 40,
                'min_monthly_hours' => 60,
                'max_monthly_hours' => 120,
            ]);

        $response = $this->importCsv([
            $this->csvRow(
                fullName: 'Updated Worker',
                israeliId: $israeliId,
                role: 'Supervisor',
                status: 'Inactive',
                hourlyCost: '75.50',
                minMonthlyHours: '100',
                maxMonthlyHours: '180',
                availability: 'Fri:C;Sat:C',
            ),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('errors', []);

        $worker->refresh();

        self::assertSame('Updated Worker', $worker->full_name);
        self::assertSame($this->supervisorRole->id, $worker->role_id);
        self::assertFalse($worker->is_active);

        $this->assertDatabaseCount('workers', 1);
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->id,
            'hourly_cost' => '75.50',
            'min_monthly_hours' => 100,
            'max_monthly_hours' => 180,
        ]);

        $this->assertAvailability($worker, [5, 6], [$this->eveningShift->id]);
    }

    public function test_worker_reimporting_same_csv_is_idempotent(): void
    {
        $rows = [
            $this->csvRow('First Worker', $this->validIsraeliId(92345678), 'General Guard', 'Active', '51.00', '80', '160', 'Sun:A|B;Mon:A|B'),
            $this->csvRow('Second Worker', $this->validIsraeliId(10234567), 'Supervisor', 'Inactive', '72.00', '100', '180', 'Tue:B|C;Wed:B|C'),
        ];

        $first = $this->importCsv($rows);
        $first
            ->assertOk()
            ->assertJsonPath('data.created', 2)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('errors', []);

        $exportAfterFirstImport = $this->exportCsv();
        $second = $this->importCsv($rows);
        $exportAfterSecondImport = $this->exportCsv();

        $second
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('errors', []);

        $this->assertDatabaseCount('workers', 2);
        $this->assertDatabaseCount('contracts', 2);
        $this->assertDatabaseCount('contract_availability', 8);
        self::assertSame($exportAfterFirstImport, $exportAfterSecondImport);
    }

    public function test_worker_import_reports_row_validation_errors(): void
    {
        $response = $this->importCsv([
            $this->csvRow(
                fullName: 'Invalid Worker',
                israeliId: '123456789',
                role: 'Pilot',
                status: 'Paused',
                hourlyCost: '-1',
                minMonthlyHours: '160',
                maxMonthlyHours: '80',
                availability: 'Mon:A;Mon:A;Mon:D',
            ),
        ]);

        $errors = $response->json('errors');
        $fields = array_column($errors, 'field');

        $response
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 1);

        self::assertContains('israeli_id', $fields);
        self::assertContains('role', $fields);
        self::assertContains('status', $fields);
        self::assertContains('hourly_cost', $fields);
        self::assertContains('max_monthly_hours', $fields);
        self::assertContains('availability', $fields);
        self::assertSame([2], array_values(array_unique(array_column($errors, 'line'))));

        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_worker_import_partially_imports_valid_rows_when_some_rows_fail(): void
    {
        $validIsraeliId = $this->validIsraeliId(11234567);

        $response = $this->importCsv([
            $this->csvRow('Valid Worker', $validIsraeliId, 'General Guard', 'Active', '50.00', '80', '160', 'Mon:A|B;Tue:A|B'),
            $this->csvRow('Bad Checksum', '123456789', 'General Guard', 'Active', '50.00', '80', '160', 'Mon:A'),
            $this->csvRow('Bad Range', $this->validIsraeliId(12234567), 'Supervisor', 'Active', '50.00', '160', '80', 'Mon:A'),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 2);

        $this->assertDatabaseCount('workers', 1);
        $this->assertDatabaseHas('workers', [
            'full_name' => 'Valid Worker',
            'israeli_id' => $validIsraeliId,
        ]);
        self::assertNotEmpty($response->json('errors'));
    }

    public function test_worker_export_round_trip_is_reimportable_without_duplicates(): void
    {
        $sampleRows = $this->csvRowCount(database_path('data/workers-sample.csv'));

        $first = $this->importFile(database_path('data/workers-sample.csv'));
        $first
            ->assertOk()
            ->assertJsonPath('data.total', $sampleRows)
            ->assertJsonPath('data.created', $sampleRows)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('errors', []);

        $this->assertDatabaseCount('workers', $sampleRows);
        $this->assertDatabaseCount('contracts', $sampleRows);

        $exportedCsv = $this->exportCsv();
        $reimportPath = $this->writeTempCsv($exportedCsv);
        $second = $this->importFile($reimportPath);
        $second
            ->assertOk()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.updated', $sampleRows)
            ->assertJsonPath('data.skipped', 0)
            ->assertJsonPath('errors', []);

        $this->assertDatabaseCount('workers', $sampleRows);
        $this->assertDatabaseCount('contracts', $sampleRows);
        self::assertSame($exportedCsv, $this->exportCsv());
    }

    public function test_worker_can_be_deleted(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345678),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create();

        $this->deleteJson("/api/workers/{$worker->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('workers', [
            'id' => $worker->id,
        ]);

        $this->getJson('/api/workers')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_all_workers_can_be_deleted(): void
    {
        $workers = Worker::factory()
            ->count(3)
            ->create(['role_id' => $this->role->id]);

        foreach ($workers as $worker) {
            Contract::factory()
                ->for($worker)
                ->withAvailability([0], [$this->morningShift->id])
                ->create();
        }

        $roster = Roster::factory()->create();
        RosterAssignment::factory()->create([
            'roster_id' => $roster->id,
            'worker_id' => $workers[0]->id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-10',
        ]);

        $this->deleteJson('/api/workers')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted', 3);

        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('roster_assignments', 0);

        $this->getJson('/api/workers')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    private function importFile(string $path): \Illuminate\Testing\TestResponse
    {
        $upload = new UploadedFile($path, 'workers.csv', 'text/csv', null, true);

        return $this->post('/api/workers/import', ['file' => $upload], ['Accept' => 'application/json']);
    }

    /**
     * Import CSV rows with the canonical header.
     *
     * @param list<array<int, string>> $rows
     */
    private function importCsv(array $rows): \Illuminate\Testing\TestResponse
    {
        return $this->importFile($this->writeTempCsv($this->csv($rows)));
    }

    /**
     * Build CSV contents using the fixed worker CSV column order.
     *
     * @param list<array<int, string>> $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, WorkerCsvService::HEADERS);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $contents = stream_get_contents($handle);
        fclose($handle);

        return $contents;
    }

    /**
     * Build one worker CSV row in the canonical column order.
     *
     * @return array<int, string>
     */
    private function csvRow(
        string $fullName,
        string $israeliId,
        string $role,
        string $status,
        string $hourlyCost,
        string $minMonthlyHours,
        string $maxMonthlyHours,
        string $availability,
    ): array {
        return [
            WorkerCsvService::FULL_NAME => $fullName,
            WorkerCsvService::ISRAELI_ID => $israeliId,
            WorkerCsvService::ROLE => $role,
            WorkerCsvService::STATUS => $status,
            WorkerCsvService::HOURLY_COST => $hourlyCost,
            WorkerCsvService::MIN_MONTHLY_HOURS => $minMonthlyHours,
            WorkerCsvService::MAX_MONTHLY_HOURS => $maxMonthlyHours,
            WorkerCsvService::AVAILABILITY => $availability,
        ];
    }

    private function exportCsv(): string
    {
        $response = $this->postJson('/api/workers/export');

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $exportId = (string) $response->json('data.export_id');

        $download = $this->get("/api/workers/export/{$exportId}/download");

        $download->assertOk();

        return $download->streamedContent();
    }

    private function writeTempCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'workers_csv_') . '.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    private function csvRowCount(string $path): int
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return count($lines) - 1;
    }

    /**
     * Assert that a worker's contract availability matches exactly.
     *
     * @param list<int> $expectedDays
     * @param list<int> $expectedShiftIds
     */
    private function assertAvailability(Worker $worker, array $expectedDays, array $expectedShiftIds): void
    {
        $contract = $worker->contract()->firstOrFail();

        foreach ($expectedDays as $day) {
            foreach ($expectedShiftIds as $shiftId) {
                $this->assertDatabaseHas('contract_availability', [
                    'contract_id' => $contract->id,
                    'day_of_week' => $day,
                    'shift_id' => $shiftId,
                ]);
            }
        }

        self::assertSame(
            count($expectedDays) * count($expectedShiftIds),
            $contract->availability()->count(),
        );
    }

    /**
     * Build a valid worker API payload with optional overrides.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function workerPayload(array $overrides = []): array
    {
        $contract = [
            'hourly_cost' => 72.50,
            'min_monthly_hours' => 120,
            'max_monthly_hours' => 180,
        ];
        $payload = [
            'full_name' => 'Test Worker',
            'israeli_id' => $this->validIsraeliId(10000000),
            'role_id' => $this->role->id,
            'is_active' => true,
            'contract' => $contract,
            'availability' => $this->availabilityPairs([0, 1, 2], [$this->morningShift->id, $this->dayShift->id]),
        ];

        $payload = array_replace($payload, $overrides);

        if (isset($overrides['contract']) && is_array($overrides['contract'])) {
            $payload['contract'] = array_replace($contract, $overrides['contract']);
        }

        return $payload;
    }

    /**
     * @param list<int> $days
     * @param list<int> $shiftIds
     * @return list<array{day_of_week: int, shift_id: int}>
     */
    private function availabilityPairs(array $days, array $shiftIds): array
    {
        $pairs = [];

        foreach ($days as $day) {
            foreach ($shiftIds as $shiftId) {
                $pairs[] = [
                    'day_of_week' => $day,
                    'shift_id' => $shiftId,
                ];
            }
        }

        return $pairs;
    }

    private function validIsraeliId(int $base): string
    {
        $baseDigits = str_pad((string) $base, 8, '0', STR_PAD_LEFT);
        $sum = 0;

        for ($index = 0; $index < 8; $index++) {
            $product = (int) $baseDigits[$index] * ($index % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 10) + ($product % 10);
        }

        $checkDigit = (10 - ($sum % 10)) % 10;

        return $baseDigits.$checkDigit;
    }
}
