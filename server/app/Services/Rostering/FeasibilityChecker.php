<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use Carbon\CarbonImmutable;

/**
 * Pre-generation fail-fast check. For each role on each date it compares the
 * available pool against peak daily demand, accounting for the 2-shifts-per-day
 * ceiling: covering D positions needs at least ceil(D / 2) distinct workers.
 * Detecting obvious impossibility up front yields a clearer alert than a partial
 * roster and avoids wasted construction work.
 */
final readonly class FeasibilityChecker
{
    /**
     * Return the list of impossibilities; an empty list means the month is
     * theoretically fillable. Each issue is an array:
     *   role_id           => int
     *   work_date         => CarbonImmutable
     *   required_workers  => int
     *   available_workers => int
     *
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>  $slots
     * @param  array<int, array{role_id: int, days: array<int, true>, ...}>  $workers
     * @return list<array{role_id: int, work_date: CarbonImmutable, required_workers: int, available_workers: int}>
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
