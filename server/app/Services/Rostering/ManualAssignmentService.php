<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Exceptions\Rostering\ManualAssignmentException;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates and removes manual assignments on rosters, enforcing the same hard
 * constraints as the automatic engine.
 */
final readonly class ManualAssignmentService
{
    /**
     * Constructor.
     *
     * @param RosterReportService $reportService
     * @return void
     */
    public function __construct(
        private RosterReportService $reportService,
    ) {}

    /**
     * Add a manual assignment after validating every hard constraint.
     *
     * @param Roster $roster
     * @param string $workerId
     * @param int $shiftId
     * @param string $workDate
     * @return RosterAssignment
     * @throws ManualAssignmentException
     */
    public function create(Roster $roster, string $workerId, int $shiftId, string $workDate): RosterAssignment
    {
        $date = CarbonImmutable::parse($workDate)->startOfDay();

        if ($date->year !== $roster->year || $date->month !== $roster->month) {
            throw ManualAssignmentException::dateOutsideRosterMonth();
        }

        $worker = Worker::query()
            ->active()
            ->whereHas('contract')
            ->with(['contract.availability'])
            ->whereKey($workerId)
            ->first();

        if ($worker === null) {
            throw ManualAssignmentException::inactiveWorker();
        }

        $shift = Shift::query()->whereKey($shiftId)->firstOrFail();

        $this->assertWorkerAvailability($worker, $date, $shiftId);
        $this->assertRoleCapacity($roster, $worker, $shiftId, $date);
        $this->assertUniqueSlot($roster, $workerId, $shiftId, $date);
        $this->assertDailyShiftLimit($roster, $workerId, $date);
        $this->assertWithinMaxHours($roster, $worker, $shift);

        return DB::transaction(function () use ($roster, $worker, $workerId, $shiftId, $date): RosterAssignment {
            $assignment = RosterAssignment::query()->create([
                'roster_id' => $roster->id,
                'worker_id' => $workerId,
                'shift_id' => $shiftId,
                'work_date' => $date->toDateString(),
                'source' => AssignmentSource::Manual,
                'hourly_cost' => $worker->contract->hourly_cost,
            ]);

            $this->reportService->refreshReports($roster);

            return $assignment;
        });
    }

    /**
     * Remove an assignment from a draft roster.
     *
     * @param Roster $roster
     * @param RosterAssignment $assignment
     * @return void
     * @throws ManualAssignmentException
     */
    public function delete(Roster $roster, RosterAssignment $assignment): void
    {
        if ((int) $assignment->roster_id !== (int) $roster->id) {
            throw ManualAssignmentException::assignmentNotInRoster();
        }

        DB::transaction(function () use ($roster, $assignment): void {
            $assignment->delete();

            $this->reportService->refreshReports($roster);
        });
    }

    /**
     * Assert that the worker is available for the given date and shift.
     *
     * @param Worker $worker
     * @param CarbonImmutable $date
     * @param int $shiftId
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertWorkerAvailability(Worker $worker, CarbonImmutable $date, int $shiftId): void
    {
        $contract = $worker->contract;

        foreach ($contract->availability as $slot) {
            if ((int) $slot->day_of_week === $date->dayOfWeek && (int) $slot->shift_id === $shiftId) {
                return;
            }
        }

        throw ManualAssignmentException::unavailableDay();
    }

    /**
     * Assert that the worker's role still has an open slot on the date and shift.
     *
     * @param Roster $roster
     * @param Worker $worker
     * @param int $shiftId
     * @param CarbonImmutable $date
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertRoleCapacity(Roster $roster, Worker $worker, int $shiftId, CarbonImmutable $date): void
    {
        $required = ShiftRoleRequirement::query()
            ->where('shift_id', $shiftId)
            ->where('role_id', $worker->role_id)
            ->value('required_count');

        // No requirement row (or zero demand) means this role is not staffed on
        // this shift at all — capacity is zero, not unlimited, so reject.
        if ($required === null || (int) $required === 0) {
            throw ManualAssignmentException::roleAtCapacity();
        }

        $assigned = RosterAssignment::query()
            ->join('workers', 'workers.israeli_id', '=', 'roster_assignments.worker_id')
            ->where('roster_assignments.roster_id', $roster->id)
            ->where('roster_assignments.shift_id', $shiftId)
            ->whereDate('roster_assignments.work_date', $date->toDateString())
            ->where('workers.role_id', $worker->role_id)
            ->count();

        if ($assigned >= (int) $required) {
            throw ManualAssignmentException::roleAtCapacity();
        }
    }

    /**
     * Assert that the worker is not already assigned to the same date and shift.
     *
     * @param Roster $roster
     * @param string $workerId
     * @param int $shiftId
     * @param CarbonImmutable $date
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertUniqueSlot(Roster $roster, string $workerId, int $shiftId, CarbonImmutable $date): void
    {
        $exists = RosterAssignment::query()
            ->where('roster_id', $roster->id)
            ->where('worker_id', $workerId)
            ->where('shift_id', $shiftId)
            ->whereDate('work_date', $date->toDateString())
            ->exists();

        if ($exists) {
            throw ManualAssignmentException::duplicateSlot();
        }
    }

    /**
     * Assert that the worker is not assigned to more than the maximum number of shifts per day.
     *
     * @param Roster $roster
     * @param string $workerId
     * @param CarbonImmutable $date
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertDailyShiftLimit(Roster $roster, string $workerId, CarbonImmutable $date): void
    {
        $shiftsOnDate = RosterAssignment::query()
            ->where('roster_id', $roster->id)
            ->where('worker_id', $workerId)
            ->whereDate('work_date', $date->toDateString())
            ->count();

        if ($shiftsOnDate >= RosteringEngine::MAX_SHIFTS_PER_DAY) {
            throw ManualAssignmentException::exceedsDailyShiftLimit();
        }
    }

    /**
     * Assert that the worker is not assigned to more than the maximum number of hours per month.
     *
     * @param Roster $roster
     * @param Worker $worker
     * @param Shift $shift
     * @return void
     * @throws ManualAssignmentException
     */
    private function assertWithinMaxHours(Roster $roster, Worker $worker, Shift $shift): void
    {
        $assignedHours = (int) RosterAssignment::query()
            ->where('roster_assignments.roster_id', $roster->id)
            ->where('roster_assignments.worker_id', $worker->israeli_id)
            ->join('shifts', 'shifts.id', '=', 'roster_assignments.shift_id')
            ->sum('shifts.duration_hours');

        $maxHours = (int) $worker->contract->max_monthly_hours;

        if ($assignedHours + (int) $shift->duration_hours > $maxHours) {
            throw ManualAssignmentException::exceedsMaxHours();
        }
    }
}
