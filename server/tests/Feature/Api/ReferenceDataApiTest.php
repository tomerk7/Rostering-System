<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ReferenceDataApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_data_seeder_creates_roles_shifts_and_requirements(): void
    {
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('shifts', 3);
        $this->assertDatabaseCount('shift_role_requirements', 9);

        foreach (ReferenceDataSeeder::REQUIRED_COUNTS_BY_ROLE_CODE as $roleCode => $requiredCount) {
            $role = Role::query()->where('code', $roleCode)->firstOrFail();

            foreach (Shift::query()->get() as $shift) {
                $this->assertDatabaseHas('shift_role_requirements', [
                    'role_id' => $role->id,
                    'shift_id' => $shift->id,
                    'required_count' => $requiredCount,
                ]);
            }
        }
    }

    public function test_reference_data_api_returns_roles_and_shifts(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->seed(ReferenceDataSeeder::class);

        $response = $this->getJson('/api/reference-data');

        $response
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.roles.0.code', 'general_guard')
            ->assertJsonPath('data.shifts.0.code', 'A')
            ->assertJsonCount(3, 'data.roles')
            ->assertJsonCount(3, 'data.shifts')
            ->assertJsonCount(9, 'data.shift_role_requirements');
    }

    public function test_reference_data_seeder_is_idempotent(): void
    {
        $this->seed(ReferenceDataSeeder::class);
        $this->seed(ReferenceDataSeeder::class);

        $this->assertDatabaseCount('roles', 3);
        $this->assertDatabaseCount('shifts', 3);
        $this->assertDatabaseCount('shift_role_requirements', 9);
        self::assertSame(9, ShiftRoleRequirement::query()->count());
    }
}
