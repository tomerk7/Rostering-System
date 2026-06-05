<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\ContractAvailableDay;
use App\Models\ContractAvailableShift;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class WorkerApiTest extends TestCase
{
    use RefreshDatabase;

    private Role $role;

    private Shift $morningShift;

    private Shift $dayShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->role = Role::query()->where('code', 'general_guard')->firstOrFail();
        $this->morningShift = Shift::query()->where('code', 'A')->firstOrFail();
        $this->dayShift = Shift::query()->where('code', 'B')->firstOrFail();
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
            ->assertJsonPath('data.0.contract.availability.days', [0, 1])
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
            ->assertJsonPath('data.contract.availability.days', [0, 1, 2]);

        $worker = Worker::query()->where('israeli_id', $payload['israeli_id'])->firstOrFail();

        $this->assertDatabaseHas('contracts', [
            'worker_id' => $worker->id,
            'min_monthly_hours' => 120,
            'max_monthly_hours' => 180,
        ]);
        $this->assertDatabaseCount('contract_available_days', 3);
        $this->assertDatabaseCount('contract_available_shifts', 2);
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
            ->assertJsonPath('data.contract.availability.shifts.0.id', $this->morningShift->id);

        $payload = $this->workerPayload([
            'full_name' => 'Updated Worker',
            'israeli_id' => $worker->israeli_id,
            'availability' => [
                'days' => [4, 5],
                'shifts' => [$this->dayShift->id],
            ],
        ]);

        $this->putJson("/api/workers/{$worker->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.full_name', 'Updated Worker')
            ->assertJsonPath('data.contract.availability.days', [4, 5])
            ->assertJsonPath('data.contract.availability.shifts.0.id', $this->dayShift->id);

        $contract = $worker->contract()->firstOrFail();

        $this->assertDatabaseMissing('contract_available_days', [
            'contract_id' => $contract->id,
            'day_of_week' => 0,
        ]);
        $this->assertDatabaseMissing('contract_available_shifts', [
            'contract_id' => $contract->id,
            'shift_id' => $this->morningShift->id,
        ]);
        $this->assertDatabaseHas('contract_available_days', [
            'contract_id' => $contract->id,
            'day_of_week' => 4,
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
            'availability' => [
                'days' => [],
                'shifts' => [999],
            ],
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'full_name',
                'israeli_id',
                'role_id',
                'contract.hourly_cost',
                'contract.max_monthly_hours',
                'availability.days',
                'availability.shifts.0',
            ]);

        $this->assertDatabaseCount('workers', 0);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_worker_save_rolls_back_when_nested_availability_write_fails(): void
    {
        ContractAvailableShift::creating(static function (): void {
            throw new RuntimeException('Forced availability failure.');
        });

        try {
            $response = $this->postJson('/api/workers', $this->workerPayload([
                'full_name' => 'Rollback Worker',
                'israeli_id' => $this->validIsraeliId(52345678),
            ]));

            $response->assertStatus(500);
        } finally {
            ContractAvailableShift::flushEventListeners();
        }

        $this->assertDatabaseMissing('workers', [
            'full_name' => 'Rollback Worker',
        ]);
        $this->assertDatabaseCount('contracts', 0);
        $this->assertDatabaseCount('contract_available_days', 0);
        $this->assertDatabaseCount('contract_available_shifts', 0);
    }

    public function test_worker_can_be_soft_deleted(): void
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

        $this->assertSoftDeleted('workers', [
            'id' => $worker->id,
        ]);

        $this->getJson('/api/workers')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
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
        $availability = [
            'days' => [0, 1, 2],
            'shifts' => [$this->morningShift->id, $this->dayShift->id],
        ];

        $payload = [
            'full_name' => 'Test Worker',
            'israeli_id' => $this->validIsraeliId(10000000),
            'role_id' => $this->role->id,
            'is_active' => true,
            'contract' => $contract,
            'availability' => $availability,
        ];

        $payload = array_replace($payload, $overrides);

        if (isset($overrides['contract']) && is_array($overrides['contract'])) {
            $payload['contract'] = array_replace($contract, $overrides['contract']);
        }

        if (isset($overrides['availability']) && is_array($overrides['availability'])) {
            $payload['availability'] = array_replace($availability, $overrides['availability']);
        }

        return $payload;
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
