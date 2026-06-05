<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use Illuminate\Database\Seeder;

final class ReferenceDataSeeder extends Seeder
{
    public const ROLES = [
        ['code' => 'general_guard', 'name' => 'General Guard'],
        ['code' => 'supervisor', 'name' => 'Supervisor'],
        ['code' => 'screener', 'name' => 'Screener'],
    ];

    public const SHIFTS = [
        ['code' => 'A', 'label' => 'morning', 'start_time' => '00:00:00', 'end_time' => '08:00:00', 'duration_hours' => 8],
        ['code' => 'B', 'label' => 'day', 'start_time' => '08:00:00', 'end_time' => '16:00:00', 'duration_hours' => 8],
        ['code' => 'C', 'label' => 'evening', 'start_time' => '16:00:00', 'end_time' => '00:00:00', 'duration_hours' => 8],
    ];

    public const REQUIRED_COUNTS_BY_ROLE_CODE = [
        'general_guard' => 6,
        'supervisor' => 1,
        'screener' => 2,
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::ROLES as $role) {
            Role::query()->updateOrCreate(
                ['code' => $role['code']],
                ['name' => $role['name']],
            );
        }

        foreach (self::SHIFTS as $shift) {
            Shift::query()->updateOrCreate(
                ['code' => $shift['code']],
                [
                    'label' => $shift['label'],
                    'start_time' => $shift['start_time'],
                    'end_time' => $shift['end_time'],
                    'duration_hours' => $shift['duration_hours'],
                ],
            );
        }

        $roleIdsByCode = Role::query()
            ->whereIn('code', array_keys(self::REQUIRED_COUNTS_BY_ROLE_CODE))
            ->pluck('id', 'code');

        $shiftIdsByCode = Shift::query()
            ->whereIn('code', array_column(self::SHIFTS, 'code'))
            ->pluck('id', 'code');

        foreach ($shiftIdsByCode as $shiftId) {
            foreach (self::REQUIRED_COUNTS_BY_ROLE_CODE as $roleCode => $requiredCount) {
                ShiftRoleRequirement::query()->updateOrCreate(
                    [
                        'shift_id' => $shiftId,
                        'role_id' => $roleIdsByCode[$roleCode],
                    ],
                    ['required_count' => $requiredCount],
                );
            }
        }
    }
}
