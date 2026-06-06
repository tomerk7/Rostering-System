<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Enums\RosterStatus;
use App\Exceptions\Rostering\ManualAssignmentException;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Creates and removes manual assignments on draft rosters, enforcing the same
 * hard constraints as the automatic engine.
 */
final readonly class ManualAssignmentService
{
    /**
     * Add a manual assignment after validating every hard constraint.
     *
     * @throws ManualAssignmentException
     */
    public function create(Roster $roster, int $workerId, int $shiftId, string $workDate): RosterAssignment
    {
        $this->assertDraft($roster);

        $date = CarbonImmutable::parse($workDate)->startOfDay();
        $this->assertDateInRosterMonth($roster, $date);

        $worker = Worker::query()
            ->active()
            ->whereHas('contract')
            ->with(['contract.availableDays', 'contract.availableShiftRows'])
            ->whereKey($workerId)
            ->first();

        if ($worker === null) {
            throw ManualAssignmentException::inactiveWorker();
        }

        $shift = Shift::query()->whereKey($shiftId)->firstOrFail();

        $this->assertWorkerAvailability($worker, $date, $shiftId);
        $this->assertUniqueSlot($roster, $workerId, $shiftId, $date);
        $this->assertDailyShiftLimit($roster, $workerId, $date);
        $this->assertWithinMaxHours($roster, $worker, $shift);

        return DB::transaction(static fn (): RosterAssignment => RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $workerId,
            'shift_id' => $shiftId,
            'work_date' => $date->toDateString(),
            'source' => AssignmentSource::Manual,
        ]));
    }

    /**
     * Remove an assignment from a draft roster.
     *
     * @throws ManualAssignmentException
     */
    public function delete(Roster $roster, RosterAssignment $assignment): void
    {
        $this->assertDraft($roster);

        if ((int) $assignment->roster_id !== (int) $roster->id) {
            throw ManualAssignmentException::assignmentNotInRoster();
        }

        DB::transaction(static function () use ($assignment): void {
            $assignment->delete();
        });
    }

    /**
     * @throws ManualAssignmentException
     */
    private function assertDraft(Roster $roster): void
    {
        if ($roster->status !== RosterStatus::Draft) {
            throw ManualAssignmentException::notDraft();
        }
    }

    /**
     * @throws ManualAssignmentException
     */
    private function assertDateInRosterMonth(Roster $roster, CarbonImmutable $date): void
    {
        if ($date->year !== $roster->year || $date->month !== $roster->month) {
            throw ManualAssignmentException::dateOutsideRosterMonth();
        }
    }

    /**
     * @throws ManualAssignmentException
     */
    private function assertWorkerAvailability(Worker $worker, CarbonImmutable $date, int $shiftId): void
    {
        $contract = $worker->contract;

        $availableDay = $contract->availableDays
            ->contains(static fn ($day): bool => (int) $day->day_of_week === $date->dayOfWeek);

        if (! $availableDay) {
            throw ManualAssignmentException::unavailableDay();
        }

        $availableShift = $contract->availableShiftRows
            ->contains(static fn ($shift): bool => (int) $shift->shift_id === $shiftId);

        if (! $availableShift) {
            throw ManualAssignmentException::unavailableShift();
        }
    }

    /**
     * @throws ManualAssignmentException
     */
    private function assertUniqueSlot(Roster $roster, int $workerId, int $shiftId, CarbonImmutable $date): void
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
     * @throws ManualAssignmentException
     */
    private function assertDailyShiftLimit(Roster $roster, int $workerId, CarbonImmutable $date): void
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
     * @throws ManualAssignmentException
     */
    private function assertWithinMaxHours(Roster $roster, Worker $worker, Shift $shift): void
    {
        $assignedHours = (int) RosterAssignment::query()
            ->where('roster_assignments.roster_id', $roster->id)
            ->where('roster_assignments.worker_id', $worker->id)
            ->join('shifts', 'shifts.id', '=', 'roster_assignments.shift_id')
            ->sum('shifts.duration_hours');

        $maxHours = (int) $worker->contract->max_monthly_hours;

        if ($assignedHours + (int) $shift->duration_hours > $maxHours) {
            throw ManualAssignmentException::exceedsMaxHours();
        }
    }
}
