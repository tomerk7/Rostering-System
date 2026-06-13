<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AssignmentSource;
use App\Enums\RosterAlertType;
use App\Models\Contract;
use App\Models\ContractAvailability;
use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterAlert;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use App\Services\Workers\Csv\WorkerCsvService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
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

    private WorkerCsvService $csvService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        Sanctum::actingAs(User::factory()->create());

        $this->csvService = $this->app->make(WorkerCsvService::class);

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
            'worker_id' => $worker->israeli_id,
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

        $this->getJson("/api/workers/{$worker->israeli_id}")
            ->assertOk()
            ->assertJsonPath('data.israeli_id', $worker->israeli_id)
            ->assertJsonPath('data.contract.availability.0.shift.id', $this->morningShift->id);

        $payload = $this->workerPayload([
            'full_name' => 'Updated Worker',
            'israeli_id' => $worker->israeli_id,
            'availability' => $this->availabilityPairs([4, 5], [$this->dayShift->id]),
        ]);

        $this->putJson("/api/workers/{$worker->israeli_id}", $payload)
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

    public function test_worker_update_rejects_lower_max_hours_when_roster_assignments_exceed_it(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(43345678),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 50,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        for ($day = 1; $day <= 20; $day++) {
            RosterAssignment::query()->create([
                'roster_id' => $roster->id,
                'worker_id' => $worker->israeli_id,
                'shift_id' => $this->morningShift->id,
                'work_date' => sprintf('2026-06-%02d', $day),
                'source' => AssignmentSource::Auto,
                'hourly_cost' => 50,
            ]);
        }

        $payload = $this->workerPayload([
            'israeli_id' => $worker->israeli_id,
            'contract' => [
                'hourly_cost' => 50,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 120,
            ],
        ]);

        $response = $this->putJson("/api/workers/{$worker->israeli_id}", $payload);

        $response
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['contract.max_monthly_hours']);

        self::assertSame(
            'Cannot lower max monthly hours to 120. Remove this worker from the roster(s) first: June 2026 (160 hours assigned).',
            $response->json('errors.contract.max_monthly_hours.0')
                ?? $response->json('errors')['contract.max_monthly_hours'][0]
                ?? null,
        );

        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->israeli_id,
            'max_monthly_hours' => 240,
        ]);
    }

    public function test_worker_update_allows_lower_max_hours_when_assignments_fit(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(44345678),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 50,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-01',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        $payload = $this->workerPayload([
            'israeli_id' => $worker->israeli_id,
            'contract' => [
                'hourly_cost' => 50,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 120,
            ],
        ]);

        $this->putJson("/api/workers/{$worker->israeli_id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.contract.max_monthly_hours', 120);
    }

    public function test_worker_create_refreshes_hours_shortfall_alert_on_existing_rosters(): void
    {
        $user = User::query()->firstOrFail();
        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        $payload = $this->workerPayload([
            'full_name' => 'New Shortfall Worker',
            'israeli_id' => $this->validIsraeliId(51345678),
            'contract' => [
                'hourly_cost' => 72.50,
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ],
        ]);

        $this->postJson('/api/workers', $payload)->assertCreated();

        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $payload['israeli_id'],
            'type' => RosterAlertType::HoursShortfall->value,
            'min_hours' => 160,
            'scheduled_hours' => 0,
        ]);
    }

    public function test_worker_edit_refreshes_hours_shortfall_alert_on_existing_rosters(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(52345678),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-01',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 999,
            'scheduled_hours' => 50,
        ]);

        $this->putJson("/api/workers/{$worker->israeli_id}", $this->workerPayload([
            'full_name' => $worker->full_name,
            'israeli_id' => $worker->israeli_id,
            'contract' => [
                'hourly_cost' => 72.50,
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ],
        ]))->assertOk();

        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'type' => RosterAlertType::HoursShortfall->value,
            'min_hours' => 160,
            'scheduled_hours' => 8,
        ]);
        $this->assertDatabaseMissing('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 999,
        ]);
    }

    public function test_worker_edit_refreshes_coverage_shortages_when_role_changes(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(62345678),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-05',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        $this->putJson("/api/workers/{$worker->israeli_id}", $this->workerPayload([
            'full_name' => $worker->full_name,
            'israeli_id' => $worker->israeli_id,
            'role_id' => $this->role->id,
        ]))->assertOk();

        $this->assertDatabaseHas('coverage_shortages', [
            'roster_id' => $roster->id,
            'work_date' => '2026-06-05 00:00:00',
            'shift_id' => $this->morningShift->id,
            'role_id' => $this->supervisorRole->id,
            'required_count' => 1,
            'assigned_count' => 0,
        ]);
    }

    public function test_worker_import_refreshes_roster_reports(): void
    {
        $user = User::query()->firstOrFail();
        $israeliId = $this->validIsraeliId(72345679);
        $worker = Worker::factory()->create([
            'full_name' => 'Import Worker',
            'israeli_id' => $israeliId,
            'role_id' => $this->role->id,
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 50,
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-01',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 999,
            'scheduled_hours' => 50,
        ]);

        $this->importCsv([
            $this->csvRow(
                fullName: 'Import Worker Updated',
                israeliId: $israeliId,
                role: 'General Guard',
                status: 'Active',
                hourlyCost: '55.00',
                minMonthlyHours: '160',
                maxMonthlyHours: '240',
                shiftA: '1|2',
            ),
        ])->assertOk();

        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'type' => RosterAlertType::HoursShortfall->value,
            'min_hours' => 160,
            'scheduled_hours' => 8,
        ]);
        $this->assertDatabaseMissing('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 999,
        ]);
    }

    public function test_worker_import_rejects_lower_max_hours_when_roster_assignments_exceed_it(): void
    {
        $user = User::query()->firstOrFail();
        $israeliId = $this->validIsraeliId(74345679);
        $worker = Worker::factory()->create([
            'full_name' => 'Dana Import',
            'israeli_id' => $israeliId,
            'role_id' => $this->supervisorRole->id,
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 75,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        for ($day = 1; $day <= 20; $day++) {
            RosterAssignment::query()->create([
                'roster_id' => $roster->id,
                'worker_id' => $worker->israeli_id,
                'shift_id' => $this->morningShift->id,
                'work_date' => sprintf('2026-06-%02d', $day),
                'source' => AssignmentSource::Auto,
                'hourly_cost' => 50,
            ]);
        }

        $response = $this->importCsv([
            $this->csvRow(
                fullName: 'Dana Import',
                israeliId: $israeliId,
                role: 'Supervisor',
                status: 'Active',
                hourlyCost: '75.00',
                minMonthlyHours: '80',
                maxMonthlyHours: '120',
                shiftA: '1-7',
            ),
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('errors.0.field', 'max_monthly_hours')
            ->assertJsonPath(
                'errors.0.message',
                'Cannot lower max monthly hours to 120. Remove this worker from the roster(s) first: June 2026 (160 hours assigned).',
            );

        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->israeli_id,
            'max_monthly_hours' => 240,
        ]);
    }

    public function test_worker_import_allows_lower_max_hours_when_only_past_roster_exceeds(): void
    {
        $user = User::query()->firstOrFail();
        $israeliId = $this->validIsraeliId(74345679);
        $worker = Worker::factory()->create([
            'full_name' => 'Past Roster Worker',
            'israeli_id' => $israeliId,
            'role_id' => $this->supervisorRole->id,
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 75,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 240,
            ]);

        $past = Carbon::now()->startOfMonth()->subMonthsNoOverflow();
        $pastRoster = Roster::factory()
            ->forPeriod((int) $past->year, (int) $past->month)
            ->create(['created_by' => $user->id]);

        for ($day = 1; $day <= 22; $day++) {
            RosterAssignment::query()->create([
                'roster_id' => $pastRoster->id,
                'worker_id' => $worker->israeli_id,
                'shift_id' => $this->morningShift->id,
                'work_date' => $past->copy()->day($day)->toDateString(),
                'source' => AssignmentSource::Auto,
                'hourly_cost' => 50,
            ]);
        }

        $this->importCsv([
            $this->csvRow(
                fullName: 'Past Roster Worker',
                israeliId: $israeliId,
                role: 'Supervisor',
                status: 'Active',
                hourlyCost: '75.00',
                minMonthlyHours: '80',
                maxMonthlyHours: '144',
                shiftA: '1-7',
            ),
        ])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.skipped', 0);

        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->israeli_id,
            'max_monthly_hours' => 144,
        ]);
    }

    public function test_worker_import_removes_assignments_outside_updated_availability(): void
    {
        $user = User::query()->firstOrFail();
        $israeliId = $this->validIsraeliId(73345679);
        $worker = Worker::factory()->create([
            'full_name' => 'Dana Import',
            'israeli_id' => $israeliId,
            'role_id' => $this->supervisorRole->id,
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([4], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 75,
                'min_monthly_hours' => 8,
                'max_monthly_hours' => 180,
            ]);

        $pastRoster = Roster::factory()
            ->forPeriod(2026, 1)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-01-01',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        $upcomingRoster = Roster::factory()
            ->forPeriod(2026, 7)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $upcomingRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-07-02',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        $this->importCsv([
            $this->csvRow(
                fullName: 'Dana Import',
                israeliId: $israeliId,
                role: 'Supervisor',
                status: 'Active',
                hourlyCost: '75.00',
                minMonthlyHours: '8',
                maxMonthlyHours: '180',
                shiftA: '1-4',
            ),
        ])->assertOk();

        $this->assertDatabaseHas('roster_assignments', [
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-01-01 00:00:00',
        ]);

        $this->assertDatabaseMissing('roster_assignments', [
            'roster_id' => $upcomingRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-07-02 00:00:00',
        ]);
        $this->assertDatabaseHas('coverage_shortages', [
            'roster_id' => $upcomingRoster->id,
            'work_date' => '2026-07-02 00:00:00',
            'shift_id' => $this->morningShift->id,
            'role_id' => $this->supervisorRole->id,
            'required_count' => 1,
            'assigned_count' => 0,
        ]);
        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $upcomingRoster->id,
            'worker_id' => $worker->israeli_id,
            'type' => RosterAlertType::HoursShortfall->value,
            'min_hours' => 8,
            'scheduled_hours' => 0,
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
            ->assertJsonCount(3, 'data.roles')
            ->assertJsonCount(3, 'data.shifts')
            ->assertJsonCount(9, 'data.shift_role_requirements')
            ->assertJsonStructure([
                'data' => [
                    'roles' => [['id', 'code', 'name']],
                    'shifts' => [['id', 'code', 'start_time', 'end_time', 'duration_hours']],
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
                shiftA: '1|3|5',
                shiftB: '1|3|5',
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
            'worker_id' => $worker->israeli_id,
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
                shiftC: '6-7',
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
            'worker_id' => $worker->israeli_id,
            'hourly_cost' => '75.50',
            'min_monthly_hours' => 100,
            'max_monthly_hours' => 180,
        ]);

        $this->assertAvailability($worker, [5, 6], [$this->eveningShift->id]);
    }

    public function test_worker_reimporting_same_csv_is_idempotent(): void
    {
        $rows = [
            $this->csvRow('First Worker', $this->validIsraeliId(92345678), 'General Guard', 'Active', '51.00', '80', '160', '1|2', '1|2'),
            $this->csvRow('Second Worker', $this->validIsraeliId(10234567), 'Supervisor', 'Inactive', '72.00', '100', '180', '', '2|3', '2|3'),
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
                israeliId: '12345',
                role: 'Pilot',
                status: 'Paused',
                hourlyCost: '-1',
                minMonthlyHours: '160',
                maxMonthlyHours: '80',
                shiftA: '8',
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
        self::assertSame(1, count(array_filter(
            $errors,
            static fn (array $error): bool => $error['field'] === 'israeli_id' && $error['line'] === 2,
        )));
        self::assertContains('role', $fields);
        self::assertContains('status', $fields);
        self::assertContains('hourly_cost', $fields);
        self::assertContains('max_monthly_hours', $fields);
        self::assertContains('00:00-08:00', $fields);
        self::assertSame([2], array_values(array_unique(array_column($errors, 'line'))));

        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_worker_import_partially_imports_valid_rows_when_some_rows_fail(): void
    {
        $validIsraeliId = $this->validIsraeliId(11234567);

        $response = $this->importCsv([
            $this->csvRow('Valid Worker', $validIsraeliId, 'General Guard', 'Active', '50.00', '80', '160', '', '1|2', '1|2'),
            $this->csvRow('Bad ID', '12345', 'General Guard', 'Active', '50.00', '80', '160', '', '1', ''),
            $this->csvRow('Bad Range', $this->validIsraeliId(12234567), 'Supervisor', 'Active', '50.00', '160', '80', '', '1', ''),
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

    public function test_worker_csv_import_parses_day_expressions_in_one_to_seven_range(): void
    {
        $singleDayId = $this->validIsraeliId(13234567);
        $this->importCsv([
            $this->csvRow(
                fullName: 'Single Day Worker',
                israeliId: $singleDayId,
                role: 'General Guard',
                status: 'Active',
                hourlyCost: '50.00',
                minMonthlyHours: '80',
                maxMonthlyHours: '160',
                shiftA: '1',
            ),
        ])->assertOk();
        $this->assertAvailability(
            Worker::query()->where('israeli_id', $singleDayId)->firstOrFail(),
            [0],
            [$this->morningShift->id],
        );

        $rangeId = $this->validIsraeliId(13334567);
        $this->importCsv([
            $this->csvRow(
                fullName: 'Range Worker',
                israeliId: $rangeId,
                role: 'General Guard',
                status: 'Active',
                hourlyCost: '50.00',
                minMonthlyHours: '80',
                maxMonthlyHours: '160',
                shiftB: '2-6',
            ),
        ])->assertOk();
        $this->assertAvailability(
            Worker::query()->where('israeli_id', $rangeId)->firstOrFail(),
            [1, 2, 3, 4, 5],
            [$this->dayShift->id],
        );

        $listId = $this->validIsraeliId(13434567);
        $this->importCsv([
            $this->csvRow(
                fullName: 'List Worker',
                israeliId: $listId,
                role: 'General Guard',
                status: 'Active',
                hourlyCost: '50.00',
                minMonthlyHours: '80',
                maxMonthlyHours: '160',
                shiftC: '1|3|5',
            ),
        ])->assertOk();
        $this->assertAvailability(
            Worker::query()->where('israeli_id', $listId)->firstOrFail(),
            [0, 2, 4],
            [$this->eveningShift->id],
        );

        $allDaysId = $this->validIsraeliId(14234567);
        $this->importCsv([
            $this->csvRow(
                fullName: 'All Days Worker',
                israeliId: $allDaysId,
                role: 'General Guard',
                status: 'Active',
                hourlyCost: '50.00',
                minMonthlyHours: '80',
                maxMonthlyHours: '160',
                shiftA: '1-7',
            ),
        ])->assertOk();
        $this->assertAvailability(
            Worker::query()->where('israeli_id', $allDaysId)->firstOrFail(),
            [0, 1, 2, 3, 4, 5, 6],
            [$this->morningShift->id],
        );

        foreach (['0', '8'] as $invalidDay) {
            $response = $this->importCsv([
                $this->csvRow(
                    fullName: 'Invalid Day Worker',
                    israeliId: $this->validIsraeliId(15234567 + (int) $invalidDay),
                    role: 'General Guard',
                    status: 'Active',
                    hourlyCost: '50.00',
                    minMonthlyHours: '80',
                    maxMonthlyHours: '160',
                    shiftA: $invalidDay,
                ),
            ]);

            $response
                ->assertOk()
                ->assertJsonPath('data.skipped', 1);

            self::assertContains('00:00-08:00', array_column($response->json('errors'), 'field'));
        }
    }

    public function test_worker_csv_export_import_round_trip_preserves_stored_day_of_week(): void
    {
        $israeliId = $this->validIsraeliId(16234567);
        $worker = Worker::factory()->create([
            'full_name' => 'Round Trip Worker',
            'israeli_id' => $israeliId,
            'role_id' => $this->role->id,
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 2, 4, 5, 6], [$this->morningShift->id, $this->dayShift->id])
            ->create([
                'hourly_cost' => 52.50,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 160,
            ]);

        $exportedCsv = $this->exportCsv();
        self::assertStringContainsString('1|3|5-7', $exportedCsv);

        $this->importFile($this->writeTempCsv($exportedCsv))->assertOk();

        $worker->refresh();
        $this->assertAvailability($worker, [0, 2, 4, 5, 6], [$this->morningShift->id, $this->dayShift->id]);
    }

    public function test_worker_can_be_deactivated(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345678),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create();

        $this->postJson("/api/workers/{$worker->israeli_id}/deactivate")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Worker deactivated successfully.');

        $this->assertDatabaseHas('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);

        $this->getJson('/api/workers?is_active=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/workers?is_active=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_worker_with_roster_assignments_can_be_deactivated(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345679),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create();

        $past = Carbon::now()->startOfMonth()->subMonthsNoOverflow();
        $pastRoster = Roster::factory()->forPeriod((int) $past->year, (int) $past->month)->create();
        $currentRoster = Roster::factory()->forPeriod((int) now()->year, (int) now()->month)->create();

        RosterAssignment::factory()->create([
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => $past->copy()->day(10)->toDateString(),
        ]);
        RosterAssignment::factory()->create([
            'roster_id' => $currentRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => now()->startOfMonth()->addDays(4)->toDateString(),
        ]);

        $this->postJson("/api/workers/{$worker->israeli_id}/deactivate")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Worker deactivated successfully.');

        $this->assertDatabaseHas('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('roster_assignments', [
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
        ]);
        $this->assertDatabaseMissing('roster_assignments', [
            'roster_id' => $currentRoster->id,
            'worker_id' => $worker->israeli_id,
        ]);
    }

    public function test_worker_deactivate_refreshes_coverage_shortages_and_alerts(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(62345680),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-05',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 999,
            'scheduled_hours' => 50,
        ]);

        $this->postJson("/api/workers/{$worker->israeli_id}/deactivate")->assertOk();

        $this->assertDatabaseHas('coverage_shortages', [
            'roster_id' => $roster->id,
            'work_date' => '2026-06-05 00:00:00',
            'shift_id' => $this->morningShift->id,
            'role_id' => $this->supervisorRole->id,
            'required_count' => 1,
            'assigned_count' => 0,
        ]);
        $this->assertDatabaseMissing('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
        ]);
    }

    public function test_worker_deactivate_keeps_past_roster_alerts_and_assignments_as_history(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'full_name' => 'History Worker',
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(62345682),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([4], [$this->morningShift->id])
            ->create();

        $past = Carbon::now()->startOfMonth()->subMonthsNoOverflow();
        $pastRoster = Roster::factory()
            ->forPeriod((int) $past->year, (int) $past->month)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => $past->copy()->day(10)->toDateString(),
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);
        RosterAlert::query()->create([
            'roster_id' => $pastRoster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'worker_name' => $worker->full_name,
            'min_hours' => 160,
            'scheduled_hours' => 120,
        ]);

        $this->postJson("/api/workers/{$worker->israeli_id}/deactivate")
            ->assertOk()
            ->assertJsonPath('message', 'Worker deactivated successfully.');

        $this->assertDatabaseHas('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('roster_assignments', [
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
        ]);
        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
            'worker_name' => 'History Worker',
        ]);

        $this->getJson("/api/rosters/{$pastRoster->id}")
            ->assertOk()
            ->assertJsonPath('data.reports.hours_shortfalls.0.worker_id', $worker->israeli_id)
            ->assertJsonPath('data.reports.hours_shortfalls.0.worker_name', 'History Worker')
            ->assertJsonPath('data.reports.hours_shortfalls.0.shortfall_hours', 40);
    }

    public function test_roster_show_returns_refreshed_reports_after_worker_deactivate(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(62345681),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-05',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        $this->postJson("/api/workers/{$worker->israeli_id}/deactivate")->assertOk();

        $response = $this->getJson("/api/rosters/{$roster->id}")->assertOk();

        $shortage = collect($response->json('data.reports.coverage_shortages'))
            ->first(static fn (array $row): bool => $row['work_date'] === '2026-06-05'
                && $row['shift_code'] === 'A'
                && $row['role_name'] === 'Supervisor');

        self::assertNotNull($shortage);
        self::assertSame(1, $shortage['missing']);
        self::assertGreaterThan(0, $response->json('data.summary.coverage_shortage_count'));
        self::assertCount(0, $response->json('data.reports.hours_shortfalls'));
    }

    public function test_all_workers_can_be_deleted(): void
    {
        $workers = Worker::factory()
            ->count(3)
            ->create(['role_id' => $this->role->id, 'is_active' => true]);

        foreach ($workers as $worker) {
            Contract::factory()
                ->for($worker)
                ->withAvailability([0], [$this->morningShift->id])
                ->create();
        }

        $currentRoster = Roster::factory()->forPeriod((int) now()->year, (int) now()->month)->create();
        RosterAssignment::factory()->create([
            'roster_id' => $currentRoster->id,
            'worker_id' => $workers[0]->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => now()->startOfMonth()->addDays(4)->toDateString(),
        ]);

        $this->postJson('/api/workers/delete-all')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'All workers deleted successfully.')
            ->assertJsonPath('data.deleted', 3);

        $this->assertDatabaseCount('workers', 3);
        $this->assertDatabaseCount('contracts', 3);
        foreach ($workers as $worker) {
            $this->assertSoftDeleted('workers', [
                'israeli_id' => $worker->israeli_id,
                'is_active' => false,
            ]);
        }
        $this->assertDatabaseMissing('roster_assignments', [
            'roster_id' => $currentRoster->id,
        ]);

        $this->getJson('/api/workers')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/workers?trashed=only')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_all_workers_delete_refreshes_roster_reports(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(62345682),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([4], [$this->morningShift->id])
            ->create([
                'min_monthly_hours' => 8,
                'max_monthly_hours' => 180,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-05',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 50,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 999,
            'scheduled_hours' => 50,
        ]);

        $this->postJson('/api/workers/delete-all')
            ->assertOk()
            ->assertJsonPath('message', 'All workers deleted successfully.')
            ->assertJsonPath('data.deleted', 1);

        $this->assertSoftDeleted('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('coverage_shortages', [
            'roster_id' => $roster->id,
            'work_date' => '2026-06-05 00:00:00',
            'shift_id' => $this->morningShift->id,
            'role_id' => $this->supervisorRole->id,
            'required_count' => 1,
            'assigned_count' => 0,
        ]);
        $this->assertDatabaseMissing('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
        ]);
    }

    public function test_worker_can_be_reactivated_via_update(): void
    {
        $user = User::query()->firstOrFail();
        $worker = Worker::factory()->create([
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(62345683),
            'is_active' => false,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([4], [$this->morningShift->id])
            ->create([
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ]);

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        $this->putJson("/api/workers/{$worker->israeli_id}", $this->workerPayload([
            'is_active' => true,
            'role_id' => $this->supervisorRole->id,
            'contract' => [
                'hourly_cost' => 72.50,
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ],
            'availability' => [
                ['day_of_week' => 4, 'shift_id' => $this->morningShift->id],
            ],
        ]))
            ->assertOk()
            ->assertJsonPath('message', 'Worker updated successfully.')
            ->assertJsonPath('data.is_active', true);

        $worker->refresh();
        self::assertTrue($worker->is_active);

        $this->getJson('/api/workers?is_active=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);

        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 160,
            'scheduled_hours' => 0,
        ]);
    }

    public function test_worker_can_be_soft_deleted(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345684),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create();

        $this->deleteJson("/api/workers/{$worker->israeli_id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Worker deleted successfully.');

        $this->assertSoftDeleted('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);

        $this->getJson('/api/workers')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/workers?trashed=only')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_soft_deleted_worker_is_hidden_from_default_list_but_inactive_worker_is_visible(): void
    {
        $inactiveWorker = Worker::factory()->inactive()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345685),
        ]);
        $deletedWorker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345686),
            'is_active' => true,
        ]);

        $this->deleteJson("/api/workers/{$deletedWorker->israeli_id}")->assertOk();

        $this->getJson('/api/workers?is_active=0')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.israeli_id', $inactiveWorker->israeli_id);

        $this->getJson('/api/workers?trashed=only')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.israeli_id', $deletedWorker->israeli_id);
    }

    public function test_soft_deleted_worker_returns_not_found_for_show_and_update(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345687),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create();

        $this->deleteJson("/api/workers/{$worker->israeli_id}")->assertOk();

        $this->getJson("/api/workers/{$worker->israeli_id}")
            ->assertNotFound();

        $this->putJson("/api/workers/{$worker->israeli_id}", $this->workerPayload([
            'is_active' => true,
        ]))->assertNotFound();
    }

    public function test_soft_deleted_worker_can_be_restored(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345688),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create();

        $this->deleteJson("/api/workers/{$worker->israeli_id}")->assertOk();

        $this->postJson("/api/workers/{$worker->israeli_id}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'Worker restored successfully.')
            ->assertJsonPath('data.is_trashed', false)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => true,
            'deleted_at' => null,
        ]);

        $this->getJson('/api/workers?is_active=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_all_archived_workers_can_be_restored(): void
    {
        $workers = Worker::factory()
            ->count(3)
            ->create(['role_id' => $this->role->id, 'is_active' => true]);

        foreach ($workers as $worker) {
            Contract::factory()
                ->for($worker)
                ->withAvailability([0], [$this->morningShift->id])
                ->create();
            $this->deleteJson("/api/workers/{$worker->israeli_id}")->assertOk();
        }

        $this->postJson('/api/workers/restore-all')
            ->assertOk()
            ->assertJsonPath('message', 'All workers restored successfully.')
            ->assertJsonPath('data.restored', 3);

        foreach ($workers as $worker) {
            $this->assertDatabaseHas('workers', [
                'israeli_id' => $worker->israeli_id,
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }

        $this->getJson('/api/workers?trashed=only')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->getJson('/api/workers?is_active=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 3);
    }

    public function test_soft_delete_removes_upcoming_assignments_preserves_past(): void
    {
        $worker = Worker::factory()->create([
            'full_name' => 'Past Roster Worker',
            'role_id' => $this->role->id,
            'israeli_id' => $this->validIsraeliId(62345689),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create();

        $past = Carbon::now()->startOfMonth()->subMonthsNoOverflow();
        $pastRoster = Roster::factory()->forPeriod((int) $past->year, (int) $past->month)->create();
        $currentRoster = Roster::factory()->forPeriod((int) now()->year, (int) now()->month)->create();

        RosterAssignment::factory()->create([
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => $past->copy()->day(10)->toDateString(),
        ]);
        RosterAssignment::factory()->create([
            'roster_id' => $currentRoster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => now()->startOfMonth()->addDays(4)->toDateString(),
        ]);

        $this->deleteJson("/api/workers/{$worker->israeli_id}")->assertOk();

        $this->assertDatabaseHas('roster_assignments', [
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
        ]);
        $this->assertDatabaseMissing('roster_assignments', [
            'roster_id' => $currentRoster->id,
            'worker_id' => $worker->israeli_id,
        ]);

        $this->getJson("/api/rosters/{$pastRoster->id}")
            ->assertOk()
            ->assertJsonPath('data.assignments.0.worker_id', $worker->israeli_id)
            ->assertJsonPath('data.assignments.0.worker_name', 'Past Roster Worker')
            ->assertJsonPath('data.assignments.0.role_id', $this->role->id)
            ->assertJsonPath('data.assignments.0.role_name', $this->role->name);
    }

    public function test_worker_import_restores_soft_deleted_worker_by_israeli_id(): void
    {
        $israeliId = $this->validIsraeliId(62345690);
        $worker = Worker::factory()->create([
            'full_name' => 'Archived Import Worker',
            'israeli_id' => $israeliId,
            'role_id' => $this->role->id,
            'is_active' => false,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 40,
                'min_monthly_hours' => 60,
                'max_monthly_hours' => 120,
            ]);

        $worker->delete();
        $this->assertSoftDeleted('workers', ['israeli_id' => $israeliId]);

        $this->importCsv([
            $this->csvRow(
                fullName: 'Restored Import Worker',
                israeliId: $israeliId,
                role: 'General Guard',
                status: 'Active',
                hourlyCost: '55.00',
                minMonthlyHours: '80',
                maxMonthlyHours: '160',
                shiftA: '1',
            ),
        ])->assertOk();

        $this->assertDatabaseHas('workers', [
            'israeli_id' => $israeliId,
            'full_name' => 'Restored Import Worker',
            'is_active' => true,
            'deleted_at' => null,
        ]);

        $this->getJson('/api/workers?is_active=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    private function importFile(string $path): TestResponse
    {
        $upload = new UploadedFile($path, 'workers.csv', 'text/csv', null, true);

        return $this->post('/api/workers/import', ['file' => $upload], ['Accept' => 'application/json']);
    }

    /**
     * Import CSV rows with the canonical header.
     *
     * @param  list<array<int, string>>  $rows
     */
    private function importCsv(array $rows): TestResponse
    {
        return $this->importFile($this->writeTempCsv($this->csv($rows)));
    }

    /**
     * Build CSV contents using the fixed worker CSV column order.
     *
     * @param  list<array<int, string>>  $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, $this->csvService->headers());

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
        string $shiftA = '',
        string $shiftB = '',
        string $shiftC = '',
    ): array {
        return [
            $fullName,
            $israeliId,
            $role,
            $status,
            $hourlyCost,
            $minMonthlyHours,
            $maxMonthlyHours,
            $shiftA,
            $shiftB,
            $shiftC,
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
        $path = tempnam(sys_get_temp_dir(), 'workers_csv_').'.csv';
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
     * @param  list<int>  $expectedDays
     * @param  list<int>  $expectedShiftIds
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
     * @param  array<string, mixed>  $overrides
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
     * @param  list<int>  $days
     * @param  list<int>  $shiftIds
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
        return str_pad((string) ($base % 1_000_000_000), 9, '0', STR_PAD_LEFT);
    }
}
