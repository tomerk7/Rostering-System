<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\AssignmentSource;
use App\Enums\RosterAlertType;
use App\Exceptions\Workers\WorkerContractException;
use App\Models\Contract;
use App\Models\ContractAvailability;
use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterAlert;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use App\Services\Workers\WorkerService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

final class WorkerServiceTest extends TestCase
{
    use RefreshDatabase;

    private WorkerService $service;

    private Role $generalGuardRole;

    private Role $supervisorRole;

    private Shift $morningShift;

    private Shift $dayShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->service = $this->app->make(WorkerService::class);
        $this->generalGuardRole = Role::query()->where('code', 'general_guard')->firstOrFail();
        $this->supervisorRole = Role::query()->where('code', 'supervisor')->firstOrFail();
        $this->morningShift = Shift::query()->where('code', 'A')->firstOrFail();
        $this->dayShift = Shift::query()->where('code', 'B')->firstOrFail();
    }

    public function test_list_returns_paginated_workers_with_default_per_page_and_relations(): void
    {
        $worker = Worker::factory()->create([
            'full_name' => 'Alpha Worker',
            'israeli_id' => $this->validIsraeliId(11111111),
            'role_id' => $this->generalGuardRole->id,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0], [$this->morningShift->id])
            ->create();

        Worker::factory()->create([
            'full_name' => 'Beta Worker',
            'israeli_id' => $this->validIsraeliId(21111111),
            'role_id' => $this->supervisorRole->id,
        ]);

        $paginator = $this->service->list(Request::create('/workers'));

        self::assertSame(2, $paginator->total());
        self::assertSame(15, $paginator->perPage());
        self::assertSame('Alpha Worker', $paginator->items()[0]->full_name);
        self::assertTrue($paginator->items()[0]->relationLoaded('role'));
        self::assertTrue($paginator->items()[0]->contract->relationLoaded('availability'));
        self::assertTrue($paginator->items()[0]->contract->availability->first()->relationLoaded('shift'));
    }

    public function test_list_applies_search_role_id_role_code_active_and_per_page_filters(): void
    {
        $matchedWorker = Worker::factory()->create([
            'full_name' => 'Matched Worker',
            'israeli_id' => $this->validIsraeliId(31111111),
            'role_id' => $this->generalGuardRole->id,
            'is_active' => true,
        ]);
        Worker::factory()->create([
            'full_name' => 'Wrong Role',
            'israeli_id' => $this->validIsraeliId(41111111),
            'role_id' => $this->supervisorRole->id,
            'is_active' => true,
        ]);
        Worker::factory()->inactive()->create([
            'full_name' => 'Matched Worker Inactive',
            'israeli_id' => $this->validIsraeliId(51111111),
            'role_id' => $this->generalGuardRole->id,
        ]);

        $request = Request::create('/workers', 'GET', [
            'search' => '31111111',
            'role_id' => $this->generalGuardRole->id,
            'role_code' => 'general_guard',
            'is_active' => '1',
            'per_page' => '250',
        ]);

        $paginator = $this->service->list($request);

        self::assertSame(1, $paginator->total());
        self::assertSame(250, $paginator->perPage());
        self::assertSame($matchedWorker->id, $paginator->items()[0]->id);
    }

    public function test_load_details_loads_worker_contract_role_and_availability(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(61111111),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([1, 2], [$this->morningShift->id, $this->dayShift->id])
            ->create();

        $loadedWorker = $this->service->loadDetails($worker);

        self::assertTrue($loadedWorker->relationLoaded('role'));
        self::assertTrue($loadedWorker->relationLoaded('contract'));
        self::assertTrue($loadedWorker->contract->relationLoaded('availability'));
        self::assertCount(4, $loadedWorker->contract->availability);
    }

    public function test_create_persists_worker_contract_and_validated_availability(): void
    {
        $worker = $this->service->create($this->workerData([
            'full_name' => 'Created By Service',
            'israeli_id' => $this->validIsraeliId(71111111),
            'availability' => $this->availabilityPairs([1, 3], [$this->morningShift->id, $this->dayShift->id]),
        ]));

        self::assertSame('Created By Service', $worker->full_name);
        self::assertTrue($worker->relationLoaded('role'));
        self::assertTrue($worker->contract->relationLoaded('availability'));
        self::assertSame(
            [
                ['day_of_week' => 1, 'shift_id' => $this->morningShift->id],
                ['day_of_week' => 1, 'shift_id' => $this->dayShift->id],
                ['day_of_week' => 3, 'shift_id' => $this->morningShift->id],
                ['day_of_week' => 3, 'shift_id' => $this->dayShift->id],
            ],
            $worker->contract->availability
                ->sortBy(static fn ($slot): string => "{$slot->day_of_week}:{$slot->shift_id}")
                ->map(static fn ($slot): array => [
                    'day_of_week' => (int) $slot->day_of_week,
                    'shift_id' => (int) $slot->shift_id,
                ])
                ->values()
                ->all(),
        );
    }

    public function test_create_deduplicates_identical_availability_pairs(): void
    {
        $worker = $this->service->create($this->workerData([
            'availability' => [
                ['day_of_week' => 1, 'shift_id' => $this->morningShift->id],
                ['day_of_week' => 1, 'shift_id' => $this->morningShift->id],
            ],
        ]));

        self::assertCount(1, $worker->contract->availability);
    }

    public function test_update_replaces_worker_contract_and_existing_availability(): void
    {
        $worker = Worker::factory()->create([
            'full_name' => 'Before Update',
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(81111111),
        ]);
        $contract = Contract::factory()
            ->for($worker)
            ->withAvailability([0, 6], [$this->morningShift->id])
            ->create([
                'hourly_cost' => 40,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 120,
            ]);

        $updatedWorker = $this->service->update($worker, $this->workerData([
            'full_name' => 'After Update',
            'israeli_id' => $worker->israeli_id,
            'role_id' => $this->supervisorRole->id,
            'is_active' => false,
            'contract' => [
                'hourly_cost' => 95.25,
                'min_monthly_hours' => 140,
                'max_monthly_hours' => 190,
            ],
            'availability' => $this->availabilityPairs([2, 4], [$this->dayShift->id]),
        ]));

        self::assertSame($contract->id, $updatedWorker->contract->id);
        self::assertSame('After Update', $updatedWorker->full_name);
        self::assertFalse($updatedWorker->is_active);
        self::assertSame($this->supervisorRole->id, $updatedWorker->role_id);
        $this->assertDatabaseHas('contracts', [
            'id' => $contract->id,
            'hourly_cost' => 95.25,
            'min_monthly_hours' => 140,
            'max_monthly_hours' => 190,
        ]);
        $this->assertDatabaseMissing('contract_availability', [
            'contract_id' => $contract->id,
            'day_of_week' => 0,
            'shift_id' => $this->morningShift->id,
        ]);
        self::assertSame(
            $this->availabilityPairs([2, 4], [$this->dayShift->id]),
            $updatedWorker->contract->availability
                ->sortBy(static fn ($slot): string => "{$slot->day_of_week}:{$slot->shift_id}")
                ->map(static fn ($slot): array => [
                    'day_of_week' => (int) $slot->day_of_week,
                    'shift_id' => (int) $slot->shift_id,
                ])
                ->values()
                ->all(),
        );
    }

    public function test_update_rejects_lower_max_hours_when_roster_assignments_exceed_it(): void
    {
        $user = User::factory()->create();
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(83111111),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create([
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
            ]);
        }

        $this->expectException(WorkerContractException::class);
        $this->expectExceptionMessage('Remove this worker from the roster(s) first: June 2026 (160 hours assigned).');

        $this->service->update($worker, $this->workerData([
            'israeli_id' => $worker->israeli_id,
            'contract' => [
                'hourly_cost' => 50,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 120,
            ],
        ]));
    }

    public function test_update_creates_contract_when_worker_does_not_have_one(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(82111111),
        ]);

        $updatedWorker = $this->service->update($worker, $this->workerData([
            'israeli_id' => $worker->israeli_id,
        ]));

        self::assertNotNull($updatedWorker->contract);
        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->israeli_id,
            'min_monthly_hours' => 120,
            'max_monthly_hours' => 180,
        ]);
    }

    public function test_create_rolls_back_when_availability_creation_fails(): void
    {
        ContractAvailability::creating(static function (): void {
            throw new RuntimeException('Forced service failure.');
        });

        try {
            $this->service->create($this->workerData([
                'full_name' => 'Rolled Back Service Worker',
                'israeli_id' => $this->validIsraeliId(91111111),
            ]));
            self::fail('The forced availability exception was not thrown.');
        } catch (RuntimeException $exception) {
            self::assertSame('Forced service failure.', $exception->getMessage());
        } finally {
            ContractAvailability::flushEventListeners();
        }

        $this->assertDatabaseMissing('workers', [
            'full_name' => 'Rolled Back Service Worker',
        ]);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_availability', 0);
    }

    public function test_reference_data_returns_roles_ordered_by_name(): void
    {
        $roles = $this->service->referenceData()['roles'];

        self::assertSame(
            ['General Guard', 'Screener', 'Supervisor'],
            $roles->pluck('name')->all(),
        );
        self::assertSame(['id', 'code', 'name'], array_keys($roles->first()->getAttributes()));
    }

    public function test_reference_data_returns_shifts_ordered_by_code(): void
    {
        $shifts = $this->service->referenceData()['shifts'];

        self::assertSame(['A', 'B', 'C'], $shifts->pluck('code')->all());
        self::assertSame(
            ['id', 'code', 'start_time', 'end_time', 'duration_hours'],
            array_keys($shifts->first()->getAttributes()),
        );
    }

    public function test_reference_data_returns_shift_role_requirements_with_nested_role_and_ordering(): void
    {
        $requirements = $this->service->referenceData()['shift_role_requirements'];

        self::assertCount(9, $requirements);

        $firstRequirement = $requirements->first();
        self::assertIsArray($firstRequirement);
        self::assertSame(['shift_id', 'role_id', 'required_count', 'role'], array_keys($firstRequirement));

        $matchedRequirement = $requirements->first(
            fn (array $requirement): bool => $requirement['shift_id'] === $this->morningShift->id
                && $requirement['role_id'] === $this->generalGuardRole->id,
        );

        self::assertNotNull($matchedRequirement);
        self::assertSame(6, $matchedRequirement['required_count']);
        self::assertSame([
            'id' => $this->generalGuardRole->id,
            'code' => 'general_guard',
            'name' => 'General Guard',
        ], $matchedRequirement['role']);

        $shiftIds = $requirements->pluck('shift_id')->all();
        self::assertSame($shiftIds, collect($shiftIds)->sort()->values()->all());

        foreach ($requirements->groupBy('shift_id') as $shiftRequirements) {
            $roleIds = $shiftRequirements->pluck('role_id')->all();
            self::assertSame($roleIds, collect($roleIds)->sort()->values()->all());
        }
    }

    public function test_reference_data_maps_required_counts_per_role_code(): void
    {
        $requirements = $this->service->referenceData()['shift_role_requirements'];

        foreach (ReferenceDataSeeder::REQUIRED_COUNTS_BY_ROLE_CODE as $roleCode => $requiredCount) {
            $roleId = Role::query()->where('code', $roleCode)->value('id');

            self::assertSame(
                3,
                $requirements->where('role_id', $roleId)->where('required_count', $requiredCount)->count(),
            );
        }
    }

    public function test_deactivate_marks_worker_inactive(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(92111111),
            'is_active' => true,
        ]);

        $this->service->deactivate($worker);

        $this->assertDatabaseHas('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);
    }

    public function test_deactivate_removes_upcoming_roster_assignments_preserves_past(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(92111112),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([4], [$this->morningShift->id])
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

        $this->service->deactivate($worker);

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

    public function test_deactivate_refreshes_coverage_shortages_for_removed_assignments(): void
    {
        $user = User::factory()->create();
        $worker = Worker::factory()->create([
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(92111113),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([4], [$this->morningShift->id])
            ->create();

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-05',
            'source' => AssignmentSource::Auto,
        ]);

        $this->service->deactivate($worker);

        $this->assertDatabaseHas('workers', [
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
    }

    public function test_delete_all_refreshes_roster_reports(): void
    {
        $user = User::factory()->create();
        $worker = Worker::factory()->create([
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(92111114),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([4], [$this->morningShift->id])
            ->create();

        $roster = Roster::factory()
            ->forPeriod(2026, 6)
            ->create(['created_by' => $user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->morningShift->id,
            'work_date' => '2026-06-05',
            'source' => AssignmentSource::Auto,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 999,
            'scheduled_hours' => 50,
        ]);

        $deleted = $this->service->deleteAll();

        self::assertSame(1, $deleted);
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

    public function test_deactivate_keeps_past_roster_alerts_as_history(): void
    {
        $worker = Worker::factory()->create([
            'full_name' => 'History Worker',
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(92111115),
        ]);

        $past = Carbon::now()->startOfMonth()->subMonthsNoOverflow();
        $pastRoster = Roster::factory()->forPeriod((int) $past->year, (int) $past->month)->create();
        $currentRoster = Roster::factory()->forPeriod((int) now()->year, (int) now()->month)->create();

        RosterAlert::query()->create([
            'roster_id' => $pastRoster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'worker_name' => $worker->full_name,
            'min_hours' => 160,
            'scheduled_hours' => 120,
        ]);
        RosterAlert::query()->create([
            'roster_id' => $currentRoster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'worker_name' => $worker->full_name,
            'min_hours' => 160,
            'scheduled_hours' => 40,
        ]);

        $this->service->deactivate($worker);

        $this->assertDatabaseHas('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
            'worker_name' => 'History Worker',
            'min_hours' => 160,
            'scheduled_hours' => 120,
        ]);
        $this->assertDatabaseMissing('roster_alerts', [
            'roster_id' => $currentRoster->id,
            'worker_id' => $worker->israeli_id,
        ]);
    }

    public function test_delete_all_keeps_past_roster_alerts_as_history(): void
    {
        $worker = Worker::factory()->create([
            'full_name' => 'History Worker',
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(92111116),
        ]);

        $past = Carbon::now()->startOfMonth()->subMonthsNoOverflow();
        $pastRoster = Roster::factory()->forPeriod((int) $past->year, (int) $past->month)->create();
        $currentRoster = Roster::factory()->forPeriod((int) now()->year, (int) now()->month)->create();

        RosterAlert::query()->create([
            'roster_id' => $pastRoster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'worker_name' => $worker->full_name,
            'min_hours' => 160,
            'scheduled_hours' => 120,
        ]);
        RosterAlert::query()->create([
            'roster_id' => $currentRoster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'worker_name' => $worker->full_name,
            'min_hours' => 160,
            'scheduled_hours' => 40,
        ]);

        $this->service->deleteAll();

        $this->assertSoftDeleted('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $pastRoster->id,
            'worker_id' => $worker->israeli_id,
            'worker_name' => 'History Worker',
        ]);
        $this->assertDatabaseMissing('roster_alerts', [
            'roster_id' => $currentRoster->id,
            'worker_id' => $worker->israeli_id,
        ]);
    }

    public function test_soft_delete_marks_worker_trashed_and_inactive(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(92111118),
            'is_active' => true,
        ]);

        $this->service->softDelete($worker);

        $this->assertSoftDeleted('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => false,
        ]);
    }

    public function test_soft_delete_removes_upcoming_roster_assignments_preserves_past(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(92111119),
            'is_active' => true,
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([4], [$this->morningShift->id])
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

        $this->service->softDelete($worker);

        $this->assertSoftDeleted('workers', [
            'israeli_id' => $worker->israeli_id,
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

    public function test_restore_clears_deleted_at_and_sets_active_status(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(92111120),
            'is_active' => true,
        ]);

        $this->service->softDelete($worker);
        $trashedWorker = Worker::withTrashed()->whereKey($worker->israeli_id)->firstOrFail();

        $restoredWorker = $this->service->restore($trashedWorker);

        self::assertFalse($restoredWorker->trashed());
        self::assertTrue($restoredWorker->is_active);
        $this->assertDatabaseHas('workers', [
            'israeli_id' => $worker->israeli_id,
            'is_active' => true,
            'deleted_at' => null,
        ]);
    }

    public function test_restore_all_restores_archived_workers_as_active(): void
    {
        $workers = Worker::factory()
            ->count(2)
            ->create(['role_id' => $this->generalGuardRole->id]);

        foreach ($workers as $worker) {
            $this->service->softDelete($worker);
        }

        $restored = $this->service->restoreAll();

        self::assertSame(2, $restored);
        foreach ($workers as $worker) {
            $this->assertDatabaseHas('workers', [
                'israeli_id' => $worker->israeli_id,
                'is_active' => true,
                'deleted_at' => null,
            ]);
        }
    }

    public function test_reactivate_worker_via_update_makes_them_schedulable_again(): void
    {
        $user = User::factory()->create();
        $worker = Worker::factory()->create([
            'role_id' => $this->supervisorRole->id,
            'israeli_id' => $this->validIsraeliId(92111117),
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

        $this->service->update($worker, $this->workerData([
            'israeli_id' => $worker->israeli_id,
            'role_id' => $this->supervisorRole->id,
            'is_active' => true,
            'availability' => $this->availabilityPairs([4], [$this->morningShift->id]),
            'contract' => [
                'min_monthly_hours' => 160,
                'max_monthly_hours' => 240,
            ],
        ]));

        self::assertTrue(Worker::query()->active()->whereKey($worker->israeli_id)->exists());
        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 160,
            'scheduled_hours' => 0,
        ]);
    }

    /**
     * Build valid service data with optional overrides.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function workerData(array $overrides = []): array
    {
        $contract = [
            'hourly_cost' => 72.50,
            'min_monthly_hours' => 120,
            'max_monthly_hours' => 180,
        ];
        $availability = $this->availabilityPairs([0, 1, 2], [$this->morningShift->id, $this->dayShift->id]);
        $data = [
            'full_name' => 'Service Worker',
            'israeli_id' => $this->validIsraeliId(10111111),
            'role_id' => $this->generalGuardRole->id,
            'is_active' => true,
            'contract' => $contract,
            'availability' => $availability,
        ];

        $data = array_replace($data, $overrides);

        if (isset($overrides['contract']) && is_array($overrides['contract'])) {
            $data['contract'] = array_replace($contract, $overrides['contract']);
        }

        return $data;
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
        return str_pad((string) ($base % 1_000_000_000), 9, '0', STR_PAD_LEFT);
    }
}
