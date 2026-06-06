<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\Contract;
use App\Models\Worker;

/**
 * Loads the active, schedulable workforce once and turns it into the engine's
 * working set: one array per worker holding immutable availability/contract data
 * plus the live counters the greedy loop mutates as it places assignments.
 */
final readonly class EligibilityResolver
{
    /**
     * Resolve eligible workers keyed by worker id.
     *
     * Each worker is an array:
     *   role_id         => int
     *   hourly_cost     => float
     *   min_hours       => int
     *   max_hours       => int
     *   days            => array<int, true>     set of available day_of_week (0..6)
     *   shifts          => array<int, true>     set of available shift ids
     *   assigned_hours  => int                  live counter, starts at 0
     *   shifts_per_date => array<string, int>   live counter, shifts placed per Y-m-d
     *
     * @return array<int, array{role_id: int, hourly_cost: float, min_hours: int, max_hours: int, days: array<int, true>, shifts: array<int, true>, assigned_hours: int, shifts_per_date: array<string, int>}>
     */
    public function resolve(): array
    {
        $workers = [];

        Worker::query()
            ->active()
            ->with(['contract.availableDays', 'contract.availableShiftRows'])
            ->orderBy('id')
            ->get()
            ->each(function (Worker $worker) use (&$workers): void {
                /** @var Contract|null $contract */
                $contract = $worker->contract;

                // A worker without a contract has no terms or availability and
                // therefore cannot be scheduled; skip them.
                if (!$contract) {
                    return;
                }

                $days = [];

                foreach ($contract->availableDays as $availableDay) {
                    $days[(int) $availableDay->day_of_week] = true;
                }

                $shifts = [];

                foreach ($contract->availableShiftRows as $availableShift) {
                    $shifts[(int) $availableShift->shift_id] = true;
                }

                $workers[(int) $worker->id] = [
                    'role_id' => (int) $worker->role_id,
                    'hourly_cost' => (float) $contract->hourly_cost,
                    'min_hours' => (int) $contract->min_monthly_hours,
                    'max_hours' => (int) $contract->max_monthly_hours,
                    'days' => $days,
                    'shifts' => $shifts,
                    'assigned_hours' => 0,
                    'shifts_per_date' => [],
                ];
            });

        return $workers;
    }
}
