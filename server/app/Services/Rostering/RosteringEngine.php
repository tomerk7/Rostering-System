<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use Carbon\CarbonImmutable;

/**
 * The greedy rostering engine. It walks the month's slots and fills each with
 * the best eligible worker, enforcing every hard constraint at placement time.
 *
 * Candidate filtering lives here; scoring, scarcity-first ordering and the
 * construction loop will be added to this class as the engine grows.
 */
final readonly class RosteringEngine
{
    /**
     * A worker may take at most this many shifts on a single calendar day.
     */
    public const int MAX_SHIFTS_PER_DAY = 2;

    /**
     * Return the ids of workers that satisfy every hard constraint for a slot:
     * role match, available that weekday and shift, not already placed in this
     * (date, shift), under the per-day shift ceiling, and within max hours.
     *
     * @param  array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}  $slot
     * @param  array<int, array{role_id: int, max_hours: int, days: array<int, true>, shifts: array<int, true>, assigned_hours: int, shifts_per_date: array<string, int>, ...}>  $workers
     * @param  array<int, true>  $assignedWorkerIds workers already placed in this slot's (date, shift)
     * @return list<int>
     */
    public function availableWorkerIds(array $slot, array $workers, array $assignedWorkerIds = []): array
    {
        $dayOfWeek = $slot['work_date']->dayOfWeek;
        $dateKey = $slot['work_date']->toDateString();

        $candidateIds = [];

        foreach ($workers as $workerId => $worker) {
            if ($worker['role_id'] !== $slot['role_id']) {
                continue;
            }

            if (isset($assignedWorkerIds[$workerId])) {
                continue;
            }

            if (! isset($worker['days'][$dayOfWeek])) {
                continue;
            }

            if (! isset($worker['shifts'][$slot['shift_id']])) {
                continue;
            }

            if (($worker['shifts_per_date'][$dateKey] ?? 0) >= self::MAX_SHIFTS_PER_DAY) {
                continue;
            }

            if ($worker['assigned_hours'] + $slot['duration_hours'] > $worker['max_hours']) {
                continue;
            }

            $candidateIds[] = $workerId;
        }

        return $candidateIds;
    }
}
