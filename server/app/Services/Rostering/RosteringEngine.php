<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\AssignmentSource;
use Carbon\CarbonImmutable;

/**
 * The greedy rostering engine. It walks the month's slots in scarcity-first
 * order and fills each with the best eligible worker, enforcing every hard
 * constraint at placement time and updating the live worker counters as it goes.
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
     * @param  array<int, true>  $assignedWorkerIds  workers already placed in this slot's (date, shift)
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

            if ($worker['max_hours'] < $worker['assigned_hours'] + $slot['duration_hours']) {
                continue;
            }

            $candidateIds[] = $workerId;
        }

        return $candidateIds;
    }

    /**
     * Run the greedy construction over every slot and return the assignments.
     *
     * Slots are processed scarcity-first. For each slot up to required_count
     * positions are filled by repeatedly picking the best candidate and updating
     * the worker's live counters (assigned_hours, shifts_per_date). When no
     * candidate remains the position is left unfilled — coverage validation later
     * turns that into a reported shortage. Every produced row is source = auto.
     *
     * Worker counters are mutated in place so the caller can read the final
     * scheduled hours for shortfall reporting after construction.
     *
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>  $slots
     * @param  array<int, array{role_id: int, hourly_cost: float, min_hours: int, max_hours: int, days: array<int, true>, shifts: array<int, true>, assigned_hours: int, shifts_per_date: array<string, int>}>  $workers
     * @return list<array{worker_id: int, shift_id: int, work_date: CarbonImmutable, source: string}>
     */
    public function generate(array $slots, array &$workers): array
    {
        $assignments = [];

        foreach ($this->orderSlots($slots) as $slot) {
            $dateKey = $slot['work_date']->toDateString();

            /** @var array<int, true> $assignedToSlot */
            $assignedToSlot = [];

            for ($position = 0; $position < $slot['required_count']; $position++) {
                $candidateIds = $this->availableWorkerIds($slot, $workers, $assignedToSlot);

                if ($candidateIds === []) {
                    break;
                }

                $bestId = $this->bestCandidate($candidateIds, $workers);

                $assignments[] = [
                    'worker_id' => $bestId,
                    'shift_id' => $slot['shift_id'],
                    'work_date' => $slot['work_date'],
                    'source' => AssignmentSource::Auto->value,
                ];

                $workers[$bestId]['assigned_hours'] += $slot['duration_hours'];
                $workers[$bestId]['shifts_per_date'][$dateKey] = ($workers[$bestId]['shifts_per_date'][$dateKey] ?? 0) + 1;
                $assignedToSlot[$bestId] = true;
            }
        }

        return $assignments;
    }

    /**
     * Order slots scarcity-first so the hardest-to-staff demand is filled before
     * the easy demand drains the shared availability (a most-constrained-variable
     * heuristic). The required_count acts as the role-scarcity proxy, giving
     * Supervisor (1) -> Screener (2) -> General Guard (6). Remaining keys make the
     * ordering total and therefore deterministic for identical input.
     *
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>  $slots
     * @return list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>
     */
    public function orderSlots(array $slots): array
    {
        usort($slots, static function (array $a, array $b): int {
            return $a['required_count'] <=> $b['required_count']
                ?: $a['role_id'] <=> $b['role_id']
                ?: $a['work_date']->getTimestamp() <=> $b['work_date']->getTimestamp()
                ?: $a['shift_id'] <=> $b['shift_id'];
        });

        return $slots;
    }

    /**
     * Pick the best worker id from a non-empty candidate set using an ordered,
     * fully tie-broken score: furthest below min_monthly_hours first (pushes
     * everyone toward their contracted minimum), then lowest hourly_cost, then
     * lowest worker id as a stable tiebreak guaranteeing deterministic output.
     *
     * @param  list<int>  $candidateIds
     * @param  array<int, array{min_hours: int, hourly_cost: float, assigned_hours: int, ...}>  $workers
     */
    public function bestCandidate(array $candidateIds, array $workers): int
    {
        $bestId = null;
        $bestDeficit = 0;
        $bestCost = 0.0;

        foreach ($candidateIds as $candidateId) {
            $worker = $workers[$candidateId];
            $deficit = $worker['min_hours'] - $worker['assigned_hours'];
            $cost = $worker['hourly_cost'];

            $isBetter = $bestId === null
                || $deficit > $bestDeficit
                || ($deficit === $bestDeficit && $cost < $bestCost)
                || ($deficit === $bestDeficit && $cost === $bestCost && $candidateId < $bestId);

            if ($isBetter) {
                $bestId = $candidateId;
                $bestDeficit = $deficit;
                $bestCost = $cost;
            }
        }

        return (int) $bestId;
    }

    /**
     * Compare assigned counts against demand for every (date, shift, role) and
     * return the understaffed slots. Each assignment's role is derived from its
     * worker (role is not stored on the assignment), then counted per slot key.
     *
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>  $slots
     * @param  list<array{worker_id: int, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
     * @param  array<int, array{role_id: int, ...}>  $workers
     * @return list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>
     */
    public function validateCoverage(array $slots, array $assignments, array $workers): array
    {
        /** @var array<string, int> $assignedByKey */
        $assignedByKey = [];

        foreach ($assignments as $assignment) {
            $roleId = $workers[$assignment['worker_id']]['role_id'];
            $key = $this->coverageKey($assignment['work_date'], $assignment['shift_id'], $roleId);
            $assignedByKey[$key] = ($assignedByKey[$key] ?? 0) + 1;
        }

        $shortages = [];

        foreach ($slots as $slot) {
            $key = $this->coverageKey($slot['work_date'], $slot['shift_id'], $slot['role_id']);
            $assigned = $assignedByKey[$key] ?? 0;

            if ($assigned < $slot['required_count']) {
                $shortages[] = [
                    'work_date' => $slot['work_date'],
                    'shift_id' => $slot['shift_id'],
                    'role_id' => $slot['role_id'],
                    'required' => $slot['required_count'],
                    'assigned' => $assigned,
                ];
            }
        }

        return $shortages;
    }

    /**
     * Report every worker left below their contracted minimum monthly hours.
     *
     * Minimum hours is a soft goal: candidate scoring prefers under-minimum
     * workers, but the roster still saves when the goal is unmet. This reads the
     * live assigned_hours counters after construction and lists each shortfall so
     * the admin sees it in the pre-save preview. Workers ordered by id for
     * deterministic output.
     *
     * @param  array<int, array{min_hours: int, assigned_hours: int, ...}>  $workers
     * @return list<array{worker_id: int, min_hours: int, scheduled_hours: int}>
     */
    public function reportHoursShortfalls(array $workers): array
    {
        $shortfalls = [];

        foreach ($workers as $workerId => $worker) {
            if ($worker['assigned_hours'] < $worker['min_hours']) {
                $shortfalls[] = [
                    'worker_id' => $workerId,
                    'min_hours' => $worker['min_hours'],
                    'scheduled_hours' => $worker['assigned_hours'],
                ];
            }
        }

        return $shortfalls;
    }

    /**
     * Build the stable aggregation key for one (date, shift, role) slot.
     * 
     * @param CarbonImmutable $date
     * @param int $shiftId
     * @param int $roleId
     * @return string
     */
    private function coverageKey(CarbonImmutable $date, int $shiftId, int $roleId): string
    {
        return $date->toDateString().'|'.$shiftId.'|'.$roleId;
    }
}
