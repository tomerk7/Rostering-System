<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use App\Services\Workers\WorkerService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReferenceDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_reference_data_returns_empty_collections_when_database_is_empty(): void
    {
        $data = $this->app->make(WorkerService::class)->referenceData();

        self::assertCount(0, $data['roles']);
        self::assertCount(0, $data['shifts']);
        self::assertCount(0, $data['shift_role_requirements']);
    }

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
