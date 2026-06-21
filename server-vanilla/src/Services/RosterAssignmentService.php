<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\Roster;
use App\Data\RosterAssignment;
use App\Data\Worker;
use App\Exceptions\AssignmentRangeException;
use App\Exceptions\ManualAssignmentException;
use App\Repositories\RosterAssignmentRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\ShiftRoleRequirementRepository;
use App\Repositories\WorkerRepository;
use App\Services\Rostering\RosteringEngine;
use App\Support\DB;
use Throwable;

/**
 * Roster-assignment reads and manual mutations for the HTTP API. Manual
 * create/delete enforce the same hard constraints as the automatic engine.
 * Controllers stay thin and delegate here.
 */
class RosterAssignmentService
{
    /**
     * Class constructor.
     *
     * @param RosterAssignmentRepository $assignments
     * @param WorkerRepository $workers
     * @param ShiftRepository $shifts
     * @param ShiftRoleRequirementRepository $requirements
     * @param RosterReportService $reportService
     * @param RosterService $rosterService
     */
    public function __construct(
        private RosterAssignmentRepository $assignments = new RosterAssignmentRepository,
        private WorkerRepository $workers = new WorkerRepository,
        private ShiftRepository $shifts = new ShiftRepository,
        private ShiftRoleRequirementRepository $requirements = new ShiftRoleRequirementRepository,
        private RosterReportService $reportService = new RosterReportService,
        private RosterService $rosterService = new RosterService,
    ) {}

    /**
     * Assignments in a date range plus the roster's monthly assigned hours by
     * worker. The range must fall within the roster month.
     *
     * @param Roster $roster
     * @param string $fromDate
     * @param string $toDate
     * @return array{
     *     assignments: list<array<string, mixed>>,
     *     from_date: string,
     *     to_date: string,
     *     assigned_hours_by_worker: array<string, int>
     * }
     *
     * @throws AssignmentRangeException|\DateMalformedStringException
     */
    public function listForRange(Roster $roster, string $fromDate, string $toDate): array
    {
        $firstDay = substr($roster->periodStart, 0, 10);
        $lastDay = (new \DateTimeImmutable($firstDay))->format('Y-m-t');

        if ($fromDate < $firstDay || $toDate > $lastDay) {
            throw AssignmentRangeException::outsideRosterMonth();
        }

        $assignments = array_map(
            static fn ($assignment): array => $assignment->toArray(),
            $this->assignments->listForRange($roster->id, $fromDate, $toDate),
        );

        return [
            'assignments' => $assignments,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'assigned_hours_by_worker' => $this->assignments->assignedHoursByWorker($roster->id),
        ];
    }

    /**
     * A single assignment by id, or null. The controller uses this to 404 before
     * mutating (route-binding parity).
     *
     * @param int $id
     * @return RosterAssignment|null
     */
    public function findAssignment(int $id): ?RosterAssignment
    {
        return $this->assignments->find($id);
    }

    /**
     * Add a manual assignment and return the refreshed roster detail view
     * (filtered to the new assignment's date).
     *
     * @param Roster $roster
     * @param string $workerId
     * @param int $shiftId
     * @param string $workDate
     * @return array<string, mixed>
     *
     * @throws ManualAssignmentException|Throwable
     */
    public function create(Roster $roster, string $workerId, int $shiftId, string $workDate): array
    {
        $date = $this->addAssignment($roster, $workerId, $shiftId, $workDate);

        return $this->rosterService->loadDetails($this->rosterService->find($roster->id), $date);
    }

    /**
     * Remove an assignment and return the refreshed roster detail view (filtered
     * to the deleted assignment's date).
     *
     * @param Roster $roster
     * @param RosterAssignment $assignment
     * @return array<string, mixed>
     *
     * @throws ManualAssignmentException|Throwable
     */
    public function delete(Roster $roster, RosterAssignment $assignment): array
    {
        if ($assignment->rosterId !== $roster->id) {
            throw ManualAssignmentException::assignmentNotInRoster();
        }

        // The assignment row carries no role/min-hours, so resolve the worker to
        // key the incremental refresh below. A pruned (trashed) worker can't
        // happen on this path — worker changes prune their own assignments first.
        $worker = $this->workers->find($assignment->workerId);

        DB::transaction(function () use ($roster, $assignment, $worker): void {
            $this->assignments->deleteById($assignment->id);

            if ($worker !== null && $worker->contract !== null) {
                $this->reportService->refreshCoverageCell($roster->id, $assignment->workDate, $assignment->shiftId, (int) $worker->role->id);
                $this->reportService->refreshWorkerShortfall($roster->id, $assignment->workerId, $worker->contract->minMonthlyHours);
            }
        });

        return $this->rosterService->loadDetails($this->rosterService->find($roster->id), $assignment->workDate);
    }

    /**
     * Validate every hard constraint, persist the assignment, and refresh the
     * roster's reports. Returns the normalized (Y-m-d) work date.
     *
     * @param Roster $roster
     * @param string $workerId
     * @param int $shiftId
     * @param string $workDate
     * @return string
     *
     * @throws ManualAssignmentException|Throwable
     */
    private function addAssignment(Roster $roster, string $workerId, int $shiftId, string $workDate): string
    {
        $timestamp = (int) strtotime($workDate);
        $date = date('Y-m-d', $timestamp);
        $dayOfWeek = (int) date('w', $timestamp);

        if ((int) date('Y', $timestamp) !== self::year($roster)
            || (int) date('n', $timestamp) !== self::month($roster)) {
            throw ManualAssignmentException::dateOutsideRosterMonth();
        }

        $worker = $this->workers->find($workerId);

        if ($worker === null || ! $worker->isActive || $worker->contract === null) {
            throw ManualAssignmentException::inactiveWorker();
        }

        $shift = $this->shifts->find($shiftId);

        if ($shift === null) {
            throw ManualAssignmentException::roleAtCapacity();
        }

        $this->assertWorkerAvailability($worker, $dayOfWeek, $shiftId);
        $this->assertRoleCapacity($roster, (int) $worker->role->id, $shiftId, $date);
        $this->assertUniqueSlot($roster, $workerId, $shiftId, $date);
        $this->assertDailyShiftLimit($roster, $workerId, $date);
        $this->assertWithinMaxHours($roster, $worker->contract->maxMonthlyHours, $worker->israeliId, $shift->durationHours);

        DB::transaction(function () use ($roster, $worker, $workerId, $shiftId, $date): void {
            $this->assignments->insert(
                $roster->id,
                $workerId,
                $shiftId,
                $date,
                'manual',
                $worker->contract->hourlyCost,
            );

            $this->reportService->refreshCoverageCell($roster->id, $date, $shiftId, (int) $worker->role->id);
            $this->reportService->refreshWorkerShortfall($roster->id, $workerId, $worker->contract->minMonthlyHours);
        });

        return $date;
    }

    /**
     * Assert that the worker is available for the given weekday and shift.
     *
     * @param Worker $worker
     * @param int $dayOfWeek
     * @param int $shiftId
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertWorkerAvailability(Worker $worker, int $dayOfWeek, int $shiftId): void
    {
        foreach ($worker->contract->availability as $slot) {
            if ($slot->dayOfWeek === $dayOfWeek && $slot->shift?->id === $shiftId) {
                return;
            }
        }

        throw ManualAssignmentException::unavailableDay();
    }

    /**
     * Assert that the worker's role still has an open slot on the date and shift.
     *
     * @param Roster $roster
     * @param int $roleId
     * @param int $shiftId
     * @param string $date
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertRoleCapacity(Roster $roster, int $roleId, int $shiftId, string $date): void
    {
        // No requirement row (or zero demand) means this role is not staffed on
        // this shift at all — capacity is zero, not unlimited, so reject.
        $required = $this->requirements->requiredCount($shiftId, $roleId);

        if ($required === 0) {
            throw ManualAssignmentException::roleAtCapacity();
        }

        $assigned = $this->assignments->countRoleAssigned($roster->id, $shiftId, $date, $roleId);

        if ($assigned >= $required) {
            throw ManualAssignmentException::roleAtCapacity();
        }
    }

    /**
     * Assert that the worker is not already assigned to the same date and shift.
     *
     * @param Roster $roster
     * @param string $workerId
     * @param int $shiftId
     * @param string $date
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertUniqueSlot(Roster $roster, string $workerId, int $shiftId, string $date): void
    {
        if ($this->assignments->slotExists($roster->id, $workerId, $shiftId, $date)) {
            throw ManualAssignmentException::duplicateSlot();
        }
    }

    /**
     * Assert that the worker is not over the per-day shift limit.
     *
     * @param Roster $roster
     * @param string $workerId
     * @param string $date
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertDailyShiftLimit(Roster $roster, string $workerId, string $date): void
    {
        if ($this->assignments->countForWorkerOnDate($roster->id, $workerId, $date) >= RosteringEngine::MAX_SHIFTS_PER_DAY) {
            throw ManualAssignmentException::exceedsDailyShiftLimit();
        }
    }

    /**
     * Assert that adding the shift keeps the worker within their monthly maximum.
     *
     * @param Roster $roster
     * @param int $maxHours
     * @param string $workerId
     * @param int $shiftDurationHours
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertWithinMaxHours(Roster $roster, int $maxHours, string $workerId, int $shiftDurationHours): void
    {
        $assignedHours = $this->assignments->sumDurationForWorker($roster->id, $workerId);

        if ($assignedHours + $shiftDurationHours > $maxHours) {
            throw ManualAssignmentException::exceedsMaxHours();
        }
    }

    /**
     * The roster's year, from period_start.
     *
     * @param Roster $roster
     * @return int
     */
    private static function year(Roster $roster): int
    {
        return (int) substr($roster->periodStart, 0, 4);
    }

    /**
     * The roster's month, from period_start.
     *
     * @param Roster $roster
     * @return int
     */
    private static function month(Roster $roster): int
    {
        return (int) substr($roster->periodStart, 5, 2);
    }
}
