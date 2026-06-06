<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use Carbon\CarbonImmutable;
use Exception;

/**
 * Validates that the roster can theoretically be generated before scheduling begins.
 */
final readonly class FeasibilityChecker
{
    /**
     * Returns staffing shortages grouped by role and date. An empty list means the month is theoretically fillable.
     * 
     * @param list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}> $slots
     * @param array<int, array{role_id: int, days: array<int, true>, ...}> $workers
     * @return list<array{role_id: int, work_date: CarbonImmutable, required_workers: int, available_workers: int}>
     * @throws Exception
     */
    public function check(array $slots, array $workers): array
    {
        /** @var array<string, CarbonImmutable> $datesByKey */
        $datesByKey = [];

        /** @var array<string, array<int, int>> $demandByDateRole */
        $demandByDateRole = [];

        foreach ($slots as $slot) {
            $dateKey = $slot['work_date']->toDateString();
            $datesByKey[$dateKey] = $slot['work_date'];
            $demandByDateRole[$dateKey][$slot['role_id']] =
                ($demandByDateRole[$dateKey][$slot['role_id']] ?? 0) + $slot['required_count'];
        }

        $issues = [];

        foreach ($demandByDateRole as $dateKey => $demandByRole) {
            $date = $datesByKey[$dateKey];
            $dayOfWeek = $date->dayOfWeek;

            foreach ($demandByRole as $roleId => $demand) {
                $requiredWorkers = (int) ceil($demand / RosteringEngine::MAX_SHIFTS_PER_DAY);
                $availableWorkers = $this->availableWorkerCount($workers, $roleId, $dayOfWeek);

                if ($availableWorkers < $requiredWorkers) {
                    $issues[] = [
                        'role_id' => $roleId,
                        'work_date' => $date,
                        'required_workers' => $requiredWorkers,
                        'available_workers' => $availableWorkers,
                    ];
                }
            }
        }

        return $issues;
    }

    /**
     * Count active workers of a role whose availability includes the weekday.
     *
     * @param  array<int, array{role_id: int, days: array<int, true>, ...}>  $workers
     */
    private function availableWorkerCount(array $workers, int $roleId, int $dayOfWeek): int
    {
        $count = 0;

        foreach ($workers as $worker) {
            if ($worker['role_id'] === $roleId && isset($worker['days'][$dayOfWeek])) {
                $count++;
            }
        }

        return $count;
    }
}
