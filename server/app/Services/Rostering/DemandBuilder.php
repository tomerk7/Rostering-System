<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\ShiftRoleRequirement;
use Carbon\CarbonImmutable;

/**
 * Expands a target month into the full list of staffing slots by crossing every
 * calendar day with the data-driven demand in shift_role_requirements.
 */
final readonly class DemandBuilder
{
    /**
     * Build every staffing slot for the target month.
     *
     * @param int $year
     * @param int $month
     * @return list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>
     * @throws Exception
     */
    public function build(int $year, int $month): array
    {
        $requirements = ShiftRoleRequirement::query()
            ->where('required_count', '>', 0)
            ->with('shift')
            ->orderBy('shift_id')
            ->orderBy('role_id')
            ->get();

        if ($requirements->isEmpty()) {
            return [];
        }

        $firstDay = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();
        $daysInMonth = $firstDay->daysInMonth;

        $slots = [];

        for ($day = 0; $day < $daysInMonth; $day++) {
            $date = $firstDay->addDays($day);

            foreach ($requirements as $requirement) {
                $slots[] = [
                    'work_date' => $date,
                    'shift_id' => (int) $requirement->shift_id,
                    'role_id' => (int) $requirement->role_id,
                    'required_count' => (int) $requirement->required_count,
                    'duration_hours' => (int) $requirement->shift->duration_hours,
                ];
            }
        }

        return $slots;
    }
}
