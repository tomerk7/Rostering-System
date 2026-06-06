<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use App\Services\Workers\Csv\WorkerCsvFormat;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class WorkerCsvApiTest extends TestCase
{
    use RefreshDatabase;

    private Role $generalGuardRole;

    private Role $supervisorRole;

    private Shift $morningShift;

    private Shift $dayShift;

    private Shift $eveningShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        Sanctum::actingAs(User::factory()->create());

        $this->generalGuardRole = Role::query()->where('code', 'general_guard')->firstOrFail();
        $this->supervisorRole = Role::query()->where('code', 'supervisor')->firstOrFail();
        $this->morningShift = Shift::query()->where('code', 'A')->firstOrFail();
        $this->dayShift = Shift::query()->where('code', 'B')->firstOrFail();
        $this->eveningShift = Shift::query()->where('code', 'C')->firstOrFail();
    }

    public function test_import_creates_workers_with_contract_and_availability(): void
    {
        $response = $this->importCsv([
            $this->csvRow(
                fullName: 'Created Worker',
                israeliId: $this->validIsraeliId(12345678),
                role: 'General Guard',
                status: 'Active',
                hourlyCost: '50.25',
                minMonthlyHours: '80',
                maxMonthlyHours: '160',
                availableDays: 'Sun|Tue|Thu',
                availableShifts: 'A|B',
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
            ->where('israeli_id', $this->validIsraeliId(12345678))
            ->firstOrFail();

        self::assertSame('Created Worker', $worker->full_name);
        self::assertSame($this->generalGuardRole->id, $worker->role_id);
        self::assertTrue($worker->is_active);

        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->id,
            'hourly_cost' => '50.25',
            'min_monthly_hours' => 80,
            'max_monthly_hours' => 160,
        ]);

        $this->assertAvailability($worker, [0, 2, 4], [$this->morningShift->id, $this->dayShift->id]);
    }

    public function test_import_updates_existing_worker_and_replaces_availability(): void
    {
        $israeliId = $this->validIsraeliId(22345678);
        $worker = Worker::factory()->create([
            'full_name' => 'Original Worker',
            'israeli_id' => $israeliId,
            'role_id' => $this->generalGuardRole->id,
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
                availableDays: 'Fri|Sat',
                availableShifts: 'C',
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

    public function test_reimporting_same_csv_is_idempotent(): void
    {
        $rows = [
            $this->csvRow('First Worker', $this->validIsraeliId(32345678), 'General Guard', 'Active', '51.00', '80', '160', 'Sun|Mon', 'A|B'),
            $this->csvRow('Second Worker', $this->validIsraeliId(42345678), 'Supervisor', 'Inactive', '72.00', '100', '180', 'Tue|Wed', 'B|C'),
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
        $this->assertDatabaseCount('contract_available_days', 4);
        $this->assertDatabaseCount('contract_available_shifts', 4);
        self::assertSame($exportAfterFirstImport, $exportAfterSecondImport);
    }

    public function test_import_reports_row_validation_errors(): void
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
                availableDays: 'Mon|Mon',
                availableShifts: 'D',
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
        self::assertContains('available_days.1', $fields);
        self::assertContains('available_shifts.0', $fields);
        self::assertSame([2], array_values(array_unique(array_column($errors, 'line'))));

        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_import_partially_imports_valid_rows_when_some_rows_fail(): void
    {
        $validIsraeliId = $this->validIsraeliId(52345678);

        $response = $this->importCsv([
            $this->csvRow('Valid Worker', $validIsraeliId, 'General Guard', 'Active', '50.00', '80', '160', 'Mon|Tue', 'A|B'),
            $this->csvRow('Bad Checksum', '123456789', 'General Guard', 'Active', '50.00', '80', '160', 'Mon', 'A'),
            $this->csvRow('Bad Range', $this->validIsraeliId(62345678), 'Supervisor', 'Active', '50.00', '160', '80', 'Mon', 'A'),
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

    public function test_export_round_trip_is_reimportable_without_duplicates(): void
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

        fputcsv($handle, WorkerCsvFormat::HEADERS);

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
        string $availableDays,
        string $availableShifts,
    ): array {
        return [
            WorkerCsvFormat::FULL_NAME => $fullName,
            WorkerCsvFormat::ISRAELI_ID => $israeliId,
            WorkerCsvFormat::ROLE => $role,
            WorkerCsvFormat::STATUS => $status,
            WorkerCsvFormat::HOURLY_COST => $hourlyCost,
            WorkerCsvFormat::MIN_MONTHLY_HOURS => $minMonthlyHours,
            WorkerCsvFormat::MAX_MONTHLY_HOURS => $maxMonthlyHours,
            WorkerCsvFormat::AVAILABLE_DAYS => $availableDays,
            WorkerCsvFormat::AVAILABLE_SHIFTS => $availableShifts,
        ];
    }

    private function exportCsv(): string
    {
        $response = $this->get('/api/workers/export');

        $response->assertOk();

        return $response->streamedContent();
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

        self::assertSame(
            $expectedDays,
            $contract->availableDays()->orderBy('day_of_week')->pluck('day_of_week')->all(),
        );
        self::assertSame(
            $expectedShiftIds,
            $contract->availableShifts()->orderBy('shifts.id')->pluck('shifts.id')->all(),
        );
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
