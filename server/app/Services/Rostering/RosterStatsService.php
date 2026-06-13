<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\Roster;
use App\Services\Rostering\Data\RosterStatsResult;
use App\Services\Rostering\Data\WorkerStatsRow;
use App\Services\Rostering\Support\StatsMath;
use Illuminate\Support\Collection;

/**
 * Per-worker statistics for a saved roster.
 *
 * Hours and cost are derived from assignment rows whose hourly_cost was
 * snapshotted at creation time, so historical stats never change when a
 * contract rate is updated later.
 *
 * Consumed by both the stats API endpoint and the roster CSV exporter so
 * the screen and the export can never disagree.
 */
final readonly class RosterStatsService
{
    /**
     * Build per-worker stat rows and a roster-level summary.
     */
    public function forRoster(Roster $roster): RosterStatsResult
    {
        $rows = $this->workerRows($roster);

        return new RosterStatsResult(
            rows: $rows,
            summary: [
                'total_cost' => round(array_sum(array_map(
                    static fn (WorkerStatsRow $row): float => $row->totalCost,
                    $rows,
                )), 2),
                'total_hours' => array_sum(array_map(
                    static fn (WorkerStatsRow $row): int => $row->actualHours,
                    $rows,
                )),
                'workers_with_shortfall' => count(array_filter(
                    $rows,
                    static fn (WorkerStatsRow $row): bool => $row->shortfallHours > 0,
                )),
                'leaderboards' => StatsMath::leaderboards($rows),
            ],
        );
    }

    /**
     * Aggregate assigned hours and snapshot cost per worker.
     *
     * @return list<WorkerStatsRow>
     */
    private function workerRows(Roster $roster): array
    {
        /** @var Collection<int, object> $records */
        $records = $roster->assignments()
            ->join('shifts', 'shifts.id', '=', 'roster_assignments.shift_id')
            ->join('workers', 'workers.israeli_id', '=', 'roster_assignments.worker_id')
            ->join('roles', 'roles.id', '=', 'workers.role_id')
            ->leftJoin('contracts', 'contracts.worker_id', '=', 'workers.israeli_id')
            ->selectRaw(
                'workers.israeli_id,
                workers.full_name,
                roles.name AS role_name,
                contracts.min_monthly_hours,
                contracts.max_monthly_hours,
                SUM(shifts.duration_hours) AS actual_hours,
                SUM(shifts.duration_hours * roster_assignments.hourly_cost) AS total_cost',
            )
            ->groupBy(
                'workers.israeli_id',
                'workers.full_name',
                'roles.name',
                'contracts.min_monthly_hours',
                'contracts.max_monthly_hours',
            )
            ->orderBy('workers.israeli_id')
            ->get();

        return $records
            ->map(function (object $record): WorkerStatsRow {
                $actualHours = (int) $record->actual_hours;
                $minHours = (int) ($record->min_monthly_hours ?? 0);
                $maxHours = (int) ($record->max_monthly_hours ?? 0);

                return WorkerStatsRow::fromHoursAndCost(
                    workerId: (string) $record->israeli_id,
                    name: (string) $record->full_name,
                    minHours: $minHours,
                    maxHours: $maxHours,
                    actualHours: $actualHours,
                    totalCost: (float) $record->total_cost,
                    shortfallHours: max(0, $minHours - $actualHours),
                    role: (string) $record->role_name,
                );
            })
            ->values()
            ->all();
    }
}
