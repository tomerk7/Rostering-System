<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Services\Rostering\Data\RosterSlot;
use App\Services\Rostering\Data\RosterWorker;
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
     * @param  array<string, RosterWorker>  $workers
     * @param  array<string, true>  $assignedWorkerIds  workers already placed in this slot's (date, shift)
     * @return list<string>
     */
    public function availableWorkerIds(RosterSlot $slot, array $workers, array $assignedWorkerIds = []): array
    {
        $dayOfWeek = $slot->workDate->dayOfWeek;
        $dateKey = $slot->workDate->toDateString();

        $candidateIds = [];

        foreach ($workers as $workerId => $worker) {
            if ($worker->roleId !== $slot->roleId) {
                continue;
            }

            if (isset($assignedWorkerIds[$workerId])) {
                continue;
            }

            if (! isset($worker->availability[$dayOfWeek][$slot->shiftId])) {
                continue;
            }

            if (($worker->shiftsPerDate[$dateKey] ?? 0) >= self::MAX_SHIFTS_PER_DAY) {
                continue;
            }

            if ($worker->maxHours < $worker->assignedHours + $slot->durationHours) {
                continue;
            }

            $candidateIds[] = (string) $workerId;
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
     * @param  list<RosterSlot>  $slots
     * @param  array<string, RosterWorker>  $workers
     * @return list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable, source: string}>
     */
    public function generate(array $slots, array $workers): array
    {
        $assignments = [];

        foreach ($this->orderSlots($slots) as $slot) {
            $dateKey = $slot->workDate->toDateString();

            /** @var array<string, true> $assignedToSlot */
            $assignedToSlot = [];

            for ($position = 0; $position < $slot->requiredCount; $position++) {
                $candidateIds = $this->availableWorkerIds($slot, $workers, $assignedToSlot);

                if (empty($candidateIds)) {
                    break;
                }

                $bestId = $this->bestCandidate($candidateIds, $workers);

                $assignments[] = [
                    'worker_id' => $bestId,
                    'shift_id' => $slot->shiftId,
                    'work_date' => $slot->workDate,
                    'source' => AssignmentSource::Auto->value,
                ];

                $workers[$bestId]->assignedHours += $slot->durationHours;
                $workers[$bestId]->shiftsPerDate[$dateKey] = ($workers[$bestId]->shiftsPerDate[$dateKey] ?? 0) + 1;
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
     * @param  list<RosterSlot>  $slots
     * @return list<RosterSlot>
     */
    public function orderSlots(array $slots): array
    {
        usort($slots, static function (RosterSlot $a, RosterSlot $b): int {
            return $a->requiredCount <=> $b->requiredCount
                ?: $a->roleId <=> $b->roleId
                ?: $a->workDate->getTimestamp() <=> $b->workDate->getTimestamp()
                ?: $a->shiftId <=> $b->shiftId;
        });

        return $slots;
    }

    /**
     * Pick the best worker id from a non-empty candidate set using an ordered,
     * fully tie-broken score: furthest below min_monthly_hours first (pushes
     * everyone toward their contracted minimum), then lowest worker id as a
     * stable tiebreak guaranteeing deterministic output.
     *
     * @param  list<string>  $candidateIds
     * @param  array<string, RosterWorker>  $workers
     */
    public function bestCandidate(array $candidateIds, array $workers): string
    {
        $bestId = null;
        $bestShortfall = 0.0;

        foreach ($candidateIds as $candidateId) {
            $worker = $workers[$candidateId];

            // Proportional shortfall: how far below the contracted minimum this
            // worker is, expressed as a fraction of that minimum so workers on
            // small and large contracts are pushed toward their minimum fairly.
            // Guarded against a zero minimum (no contracted floor).
            $deficit = $worker->minHours - $worker->assignedHours;
            $shortfall = $worker->minHours > 0 ? $deficit / $worker->minHours : 0.0;

            if (empty($bestId)) {
                $bestId = $candidateId;
                $bestShortfall = $shortfall;

                continue;
            }

            // Ordered, fully tie-broken comparison: largest shortfall first,
            // then lowest israeli_id for deterministic output.
            if ($shortfall > $bestShortfall) {
                $comparison = 1;
            } elseif ($shortfall < $bestShortfall) {
                $comparison = -1;
            } elseif ($bestId < $candidateId) {
                $comparison = -1;
            } elseif ($bestId > $candidateId) {
                $comparison = 1;
            } else {
                $comparison = 0;
            }

            if ($comparison > 0) {
                $bestId = $candidateId;
                $bestShortfall = $shortfall;
            }
        }

        return (string) $bestId;
    }

    /**
     * Compare assigned counts against demand for every (date, shift, role) and
     * return the understaffed slots. Each assignment's role is derived from its
     * worker (role is not stored on the assignment), then counted per slot key.
     *
     * @param  list<RosterSlot>  $slots
     * @param  list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
     * @param  array<string, RosterWorker>  $workers
     * @return list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>
     */
    public function validateCoverage(array $slots, array $assignments, array $workers): array
    {
        /** @var array<string, int> $assignedByKey */
        $assignedByKey = [];

        foreach ($assignments as $assignment) {
            $roleId = $workers[$assignment['worker_id']]->roleId;
            $key = $this->coverageKey($assignment['work_date'], $assignment['shift_id'], $roleId);
            $assignedByKey[$key] = ($assignedByKey[$key] ?? 0) + 1;
        }

        $shortages = [];

        foreach ($slots as $slot) {
            $key = $this->coverageKey($slot->workDate, $slot->shiftId, $slot->roleId);
            $assigned = $assignedByKey[$key] ?? 0;

            if ($assigned < $slot->requiredCount) {
                $shortages[] = [
                    'work_date' => $slot->workDate,
                    'shift_id' => $slot->shiftId,
                    'role_id' => $slot->roleId,
                    'required' => $slot->requiredCount,
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
     * @param  array<string, RosterWorker>  $workers
     * @return list<array{worker_id: string, min_hours: int, scheduled_hours: int}>
     */
    public function reportHoursShortfalls(array $workers): array
    {
        $shortfalls = [];

        foreach ($workers as $workerId => $worker) {
            if ($worker->assignedHours < $worker->minHours) {
                $shortfalls[] = [
                    'worker_id' => (string) $workerId,
                    'min_hours' => $worker->minHours,
                    'scheduled_hours' => $worker->assignedHours,
                ];
            }
        }

        return $shortfalls;
    }

    /**
     * Build the stable aggregation key for one (date, shift, role) slot.
     */
    private function coverageKey(CarbonImmutable $date, int $shiftId, int $roleId): string
    {
        return $date->toDateString().'|'.$shiftId.'|'.$roleId;
    }
}
