<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Exceptions\Rostering\AssignmentRangeException;
use App\Exceptions\Rostering\ManualAssignmentException;
use App\Models\Roster;
use App\Models\RosterAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Loads and mutates roster assignments for the HTTP API.
 */
final readonly class RosterAssignmentService
{
    public function __construct(
        private ManualAssignmentService $manualAssignmentService,
        private RosterService $rosterService,
    ) {}

    /**
     * List assignments in a date range with monthly assigned hours by worker.
     *
     * @return array{
     *     assignments: Collection<int, RosterAssignment>,
     *     from_date: string,
     *     to_date: string,
     *     assigned_hours_by_worker: array<string, int>
     * }
     *
     * @throws AssignmentRangeException
     */
    public function listForRange(Roster $roster, string $fromDate, string $toDate): array
    {
        $from = CarbonImmutable::parse($fromDate)->startOfDay();
        $to = CarbonImmutable::parse($toDate)->startOfDay();
        $rosterStart = CarbonImmutable::create($roster->year, $roster->month, 1)->startOfDay();
        $rosterEnd = $rosterStart->endOfMonth()->startOfDay();

        if ($from->lt($rosterStart) || $to->gt($rosterEnd)) {
            throw AssignmentRangeException::outsideRosterMonth();
        }

        $assignments = $roster->assignments()
            ->with(['worker.role', 'shift'])
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('work_date')
            ->orderBy('shift_id')
            ->orderBy('worker_id')
            ->get();

        $assignedHoursByWorker = $roster->assignments()
            ->join('shifts', 'shifts.id', '=', 'roster_assignments.shift_id')
            ->selectRaw('roster_assignments.worker_id, SUM(shifts.duration_hours) AS assigned_hours')
            ->groupBy('roster_assignments.worker_id')
            ->pluck('assigned_hours', 'worker_id')
            ->map(static fn (mixed $hours): int => (int) $hours)
            ->all();

        return [
            'assignments' => $assignments,
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'assigned_hours_by_worker' => $assignedHoursByWorker,
        ];
    }

    /**
     * Add a manual assignment and return the refreshed roster view.
     *
     * @param Roster $roster
     * @param string $workerId
     * @param int $shiftId
     * @param string $workDate
     * @return Roster
     * @throws ManualAssignmentException
     */
    public function create(Roster $roster, string $workerId, int $shiftId, string $workDate): Roster
    {
        $assignment = $this->manualAssignmentService->create($roster, $workerId, $shiftId, $workDate);

        $roster->refresh();

        return $this->rosterService->loadDetails($roster, $assignment->work_date->toDateString());
    }

    /**
     * Remove an assignment and return the refreshed roster view.
     * 
     * @param Roster $roster
     * @param RosterAssignment $assignment
     * @return Roster
     *
     * @throws ManualAssignmentException
     */
    public function delete(Roster $roster, RosterAssignment $assignment): Roster
    {
        $workDate = $assignment->work_date->toDateString();

        $this->manualAssignmentService->delete($roster, $assignment);

        $roster->refresh();

        return $this->rosterService->loadDetails($roster, $workDate);
    }
}
