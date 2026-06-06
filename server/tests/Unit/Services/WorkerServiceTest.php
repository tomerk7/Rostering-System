<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Contract;
use App\Models\ContractAvailableShift;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use App\Services\Workers\WorkerService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
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

        $this->service = new WorkerService();
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
        self::assertTrue($paginator->items()[0]->contract->relationLoaded('availableDays'));
        self::assertTrue($paginator->items()[0]->contract->relationLoaded('availableShifts'));
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
        self::assertTrue($loadedWorker->contract->relationLoaded('availableDays'));
        self::assertTrue($loadedWorker->contract->relationLoaded('availableShifts'));
    }

    public function test_create_persists_worker_contract_and_validated_availability(): void
    {
        $worker = $this->service->create($this->workerData([
            'full_name' => 'Created By Service',
            'israeli_id' => $this->validIsraeliId(71111111),
            'availability' => [
                'days' => [1, 3],
                'shifts' => [$this->morningShift->id, $this->dayShift->id],
            ],
        ]));

        self::assertSame('Created By Service', $worker->full_name);
        self::assertTrue($worker->relationLoaded('role'));
        self::assertTrue($worker->contract->relationLoaded('availableDays'));
        self::assertSame([1, 3], $worker->contract->availableDays->pluck('day_of_week')->all());
        self::assertSame(
            [$this->morningShift->id, $this->dayShift->id],
            $worker->contract->availableShiftRows()->orderBy('shift_id')->pluck('shift_id')->all(),
        );
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
            'availability' => [
                'days' => [2, 4],
                'shifts' => [$this->dayShift->id],
            ],
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
        $this->assertDatabaseMissing('contract_available_days', [
            'contract_id' => $contract->id,
            'day_of_week' => 0,
        ]);
        self::assertSame([2, 4], $updatedWorker->contract->availableDays->pluck('day_of_week')->all());
        self::assertSame([$this->dayShift->id], $updatedWorker->contract->availableShiftRows()->pluck('shift_id')->all());
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
            'worker_id' => $worker->id,
            'min_monthly_hours' => 120,
            'max_monthly_hours' => 180,
        ]);
    }

    public function test_create_rolls_back_when_availability_creation_fails(): void
    {
        ContractAvailableShift::creating(static function (): void {
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
            ContractAvailableShift::flushEventListeners();
        }

        $this->assertDatabaseMissing('workers', [
            'full_name' => 'Rolled Back Service Worker',
        ]);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_available_days', 0);
        $this->assertDatabaseCount('contract_available_shifts', 0);
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
            ['id', 'code', 'label', 'start_time', 'end_time', 'duration_hours'],
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

    public function test_delete_removes_worker(): void
    {
        $worker = Worker::factory()->create([
            'role_id' => $this->generalGuardRole->id,
            'israeli_id' => $this->validIsraeliId(92111111),
        ]);

        $this->service->delete($worker);

        $this->assertDatabaseMissing('workers', [
            'id' => $worker->id,
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
        $availability = [
            'days' => [0, 1, 2],
            'shifts' => [$this->morningShift->id, $this->dayShift->id],
        ];
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

        if (isset($overrides['availability']) && is_array($overrides['availability'])) {
            $data['availability'] = array_replace($availability, $overrides['availability']);
        }

        return $data;
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
