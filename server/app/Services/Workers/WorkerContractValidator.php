<?php

declare(strict_types=1);

namespace App\Services\Workers;

use App\Exceptions\Workers\WorkerContractException;
use App\Models\RosterAssignment;
use Illuminate\Support\Carbon;

/**
 * Validates worker contract changes against current and future roster assignments.
 */
final readonly class WorkerContractValidator
{
    /**
     * Current and future rosters where the worker already exceeds the proposed max monthly hours.
     *
     * @return list<array{period_label: string, assigned_hours: int}>
     */
    public function rosterHourConflicts(string $workerId, int $maxMonthlyHours): array
    {
        return RosterAssignment::query()
            ->join('rosters', 'rosters.id', '=', 'roster_assignments.roster_id')
            ->join('shifts', 'shifts.id', '=', 'roster_assignments.shift_id')
            ->where('roster_assignments.worker_id', $workerId)
            ->whereDate('rosters.period_start', '>=', Carbon::now()->startOfMonth()->toDateString())
            ->groupBy('rosters.id', 'rosters.period_start')
            ->havingRaw('SUM(shifts.duration_hours) > ?', [$maxMonthlyHours])
            ->selectRaw('rosters.period_start, SUM(shifts.duration_hours) AS assigned_hours')
            ->orderBy('rosters.period_start')
            ->get()
            ->map(static function (RosterAssignment $row): array {
                $periodStart = Carbon::parse($row->period_start);

                return [
                    'period_label' => $periodStart->format('F Y'),
                    'assigned_hours' => (int) $row->assigned_hours,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Ensure the worker's proposed max monthly hours is not below assigned roster hours.
     *
     * @param string $workerId
     * @param int $maxMonthlyHours
     * @return void
     * @throws WorkerContractException
     */
    public function assertMaxHoursAllowed(string $workerId, int $maxMonthlyHours): void
    {
        $conflicts = $this->rosterHourConflicts($workerId, $maxMonthlyHours);

        if ($conflicts !== []) {
            throw WorkerContractException::maxHoursBelowAssignedHours($maxMonthlyHours, $conflicts);
        }
    }
}
