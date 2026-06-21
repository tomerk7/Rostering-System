<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\CoverageShortage;
use App\Data\Roster;
use App\Data\RosterAlert;
use App\Repositories\CoverageShortageRepository;
use App\Repositories\RosterAlertRepository;
use App\Repositories\RosterAssignmentRepository;
use App\Repositories\RosterRepository;
use App\Repositories\ShiftRoleRequirementRepository;
use App\Services\Rostering\Data\GenerationResult;
use App\Services\Rostering\Data\RosterWorker;
use App\Services\Rostering\RosterGenerator;
use App\Support\DB;
use App\Support\StatsMath;
use App\Support\WorkerStatsRow;
use Carbon\CarbonImmutable;
use Exception;
use Throwable;

/**
 * Loads, formats, and recomputes persisted roster reports (coverage shortages +
 * hours shortfalls), per-worker statistics, and the roster summary. `loadReport`
 * and `forRoster` are the read halves.
 */
class RosterReportService
{
    /**
     * Class constructor.
     *
     * @param CoverageShortageRepository $coverageShortages
     * @param RosterAlertRepository $alerts
     * @param RosterAssignmentRepository $assignments
     * @param RosterRepository $rosters
     * @param RosterGenerator $generator
     * @param ShiftRoleRequirementRepository $requirements
     */
    public function __construct(
        private CoverageShortageRepository $coverageShortages = new CoverageShortageRepository,
        private RosterAlertRepository $alerts = new RosterAlertRepository,
        private RosterAssignmentRepository $assignments = new RosterAssignmentRepository,
        private RosterRepository $rosters = new RosterRepository,
        private RosterGenerator $generator = new RosterGenerator,
        private ShiftRoleRequirementRepository $requirements = new ShiftRoleRequirementRepository,
    ) {}

    /**
     * The persisted reports and summary for a saved roster. `assignmentCount` is
     * the roster's full assignment count (drives summary.assignment_count).
     *
     * @param int $rosterId
     * @param int $assignmentCount
     * @return array{
     *     reports: array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>},
     *     summary: array<string, int>
     * }
     */
    public function loadReport(int $rosterId, int $assignmentCount): array
    {
        $coverageShortages = array_map(
            static fn (CoverageShortage $shortage): array => $shortage->toArray(),
            $this->coverageShortages->forRoster($rosterId),
        );

        $hoursShortfalls = array_map(
            static fn (RosterAlert $alert): array => $alert->toArray(),
            $this->alerts->hoursShortfallForRoster($rosterId),
        );

        return [
            'reports' => [
                'coverage_shortages' => $coverageShortages,
                'hours_shortfalls' => $hoursShortfalls,
            ],
            'summary' => [
                'assignment_count' => $assignmentCount,
                'coverage_shortage_count' => count($coverageShortages),
                'hours_shortfall_count' => count($hoursShortfalls),
                'has_coverage_shortages' => count($coverageShortages),
                'has_hours_shortfalls' => count($hoursShortfalls),
            ],
        ];
    }

    /**
     * Per-worker stat rows plus a roster-level summary, as the API array.
     *
     * @param Roster $roster
     * @return array{rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function forRoster(Roster $roster): array
    {
        $rows = $this->workerRows($roster->id);

        return [
            'rows' => array_map(static fn (WorkerStatsRow $row): array => $row->toArray(), $rows),
            'summary' => [
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
        ];
    }

    /**
     * Aggregate assigned hours and snapshot cost per worker into stat rows.
     *
     * @param int $rosterId
     * @return list<WorkerStatsRow>
     */
    private function workerRows(int $rosterId): array
    {
        return array_map(static function (array $record): WorkerStatsRow {
            $actualHours = $record['actual_hours'];
            $minHours = $record['min_monthly_hours'] ?? 0;
            $maxHours = $record['max_monthly_hours'] ?? 0;

            return new WorkerStatsRow(
                workerId: $record['israeli_id'],
                name: $record['full_name'],
                role: $record['role_name'],
                minHours: $minHours,
                maxHours: $maxHours,
                actualHours: $actualHours,
                totalCost: round($record['total_cost'], 2),
                shortfallHours: max(0, $minHours - $actualHours),
            );
        }, $this->assignments->statsRows($rosterId));
    }

    /**
     * Persist the reports from a fresh generation run: coverage shortages and
     * hours-shortfall alerts.
     *
     * @param int $rosterId
     * @param GenerationResult $result
     * @return void
     */
    public function insertAlerts(int $rosterId, GenerationResult $result): void
    {
        $this->insertCoverage($rosterId, $result->coverageShortages);
        $this->alerts->insertHoursShortfall($rosterId, $result->hoursShortfalls);
    }

    /**
     * Incrementally refresh the coverage shortage for the single (date, shift,
     * role) cell an assignment add/delete touched.
     *
     * @param int $rosterId
     * @param string $workDate
     * @param int $shiftId
     * @param int $roleId
     * @return void
     */
    public function refreshCoverageCell(int $rosterId, string $workDate, int $shiftId, int $roleId): void
    {
        $required = $this->requirements->requiredCount($shiftId, $roleId);
        $assigned = $this->assignments->countRoleAssigned($rosterId, $shiftId, $workDate, $roleId);

        $this->coverageShortages->deleteCell($rosterId, $workDate, $shiftId, $roleId);

        if ($assigned < $required) {
            $this->coverageShortages->insertMany($rosterId, [[
                'work_date' => $workDate,
                'shift_id' => $shiftId,
                'role_id' => $roleId,
                'required' => $required,
                'assigned' => $assigned,
            ]]);
        }
    }

    /**
     * Incrementally refresh the hours-shortfall alert for the single worker an
     * assignment add/delete touched.
     *
     * @param int $rosterId
     * @param string $workerId
     * @param int $minHours
     * @return void
     */
    public function refreshWorkerShortfall(int $rosterId, string $workerId, int $minHours): void
    {
        $scheduled = $this->assignments->sumDurationForWorker($rosterId, $workerId);

        $this->alerts->deleteHoursShortfallForWorkers($rosterId, [$workerId]);

        if ($scheduled < $minHours) {
            $this->alerts->insertHoursShortfall($rosterId, [[
                'worker_id' => $workerId,
                'min_hours' => $minHours,
                'scheduled_hours' => $scheduled,
            ]]);
        }
    }

    /**
     * Refresh alerts for the given (changed) workers and recompute coverage only
     * in upcoming rosters where those workers had assignments. Invoked after worker create/update/(de)activate/
     * delete/restore and CSV import.
     *
     * @param list<string> $workerIds
     * @return void
     * @throws Throwable
     */
    public function refreshReportsForWorkers(array $workerIds): void
    {
        $workerIds = array_values(array_unique($workerIds));

        if ($workerIds === []) {
            return;
        }

        // Post-change worker state is the same across all rosters, so resolve once.
        $workers = $this->generator->resolveWorkers();

        foreach ($this->rosters->upcoming() as $roster) {
            DB::transaction(function () use ($roster, $workerIds, $workers): void {
                $hadAssignments = $this->invalidateChangedWorkerAssignments($roster, $workerIds, $workers);

                $this->rebuildWorkerAlerts($roster, $workerIds, $workers);

                if ($hadAssignments) {
                    $this->recomputeCoverage($roster);
                }
            });
        }
    }

    /**
     * Recompute coverage shortages for every upcoming roster (used after delete-all).
     *
     * @return void
     * @throws Throwable
     */
    public function refreshCoverageForUpcomingRosters(): void
    {
        foreach ($this->rosters->upcoming() as $roster) {
            DB::transaction(function () use ($roster): void {
                $this->recomputeCoverage($roster);
            });
        }
    }

    /**
     * Remove every alert from upcoming rosters (preserving past alerts as
     * history).
     *
     * @return void
     */
    public function removeUpcomingAlerts(): void
    {
        $rosterIds = array_map(static fn (Roster $roster): int => $roster->id, $this->rosters->upcoming());

        $this->alerts->deleteAllForRosters($rosterIds);
    }

    /**
     * Delete the changed workers' assignments that no longer match their current
     * availability/active status, returning whether they had any assignments in
     * the roster at all.
     *
     * @param Roster $roster
     * @param  list<string>  $workerIds
     * @param  array<string, RosterWorker>  $workers
     * @return bool
     */
    private function invalidateChangedWorkerAssignments(Roster $roster, array $workerIds, array $workers): bool
    {
        $assignments = $this->assignments->rawForRosterWorkers($roster->id, $workerIds);

        if ($assignments === []) {
            return false;
        }

        $invalidIds = [];

        foreach ($assignments as $assignment) {
            $dayOfWeek = (int) date('w', (int) strtotime($assignment['work_date']));

            if (! isset($workers[$assignment['worker_id']]->availability[$dayOfWeek][$assignment['shift_id']])) {
                $invalidIds[] = $assignment['id'];
            }
        }

        $this->assignments->deleteByIds($invalidIds);

        return true;
    }

    /**
     * Rebuild hours-shortfall alerts for the given workers in a roster (delete
     * theirs, re-insert for those still scheduled below their minimum).
     *
     * @param Roster $roster
     * @param  list<string>  $workerIds
     * @param  array<string, RosterWorker>  $workers
     * @return void
     */
    private function rebuildWorkerAlerts(Roster $roster, array $workerIds, array $workers): void
    {
        $this->alerts->deleteHoursShortfallForWorkers($roster->id, $workerIds);

        $scheduledHours = $this->assignments->scheduledHoursForWorkers($roster->id, $workerIds);

        $shortfalls = [];

        foreach ($workerIds as $workerId) {
            // A worker absent from the map is inactive/trashed/contract-less — skip.
            if (! isset($workers[$workerId])) {
                continue;
            }

            $scheduled = $scheduledHours[$workerId] ?? 0;
            $minHours = $workers[$workerId]->minHours;

            if ($scheduled < $minHours) {
                $shortfalls[] = [
                    'worker_id' => $workerId,
                    'min_hours' => $minHours,
                    'scheduled_hours' => $scheduled,
                ];
            }
        }

        $this->alerts->insertHoursShortfall($roster->id, $shortfalls);
    }

    /**
     * Recompute and replace coverage shortages for a roster from its current
     * assignments (alerts untouched).
     *
     * @param Roster $roster
     * @return void
     * @throws Exception
     */
    private function recomputeCoverage(Roster $roster): void
    {
        $year = (int) substr($roster->periodStart, 0, 4);
        $month = (int) substr($roster->periodStart, 5, 2);

        $saved = array_map(static fn (array $a): array => [
            'worker_id' => $a['worker_id'],
            'shift_id' => $a['shift_id'],
            'work_date' => CarbonImmutable::parse($a['work_date'])->startOfDay(),
        ], $this->assignments->rawForRoster($roster->id));

        $reports = $this->generator->recomputeReports($year, $month, $saved);

        $this->coverageShortages->deleteForRoster($roster->id);
        $this->insertCoverage($roster->id, $reports['coverageShortages']);
    }

    /**
     * Persist recomputed coverage shortages, converting the engine's Carbon
     * work_date to the plain date string the repository inserts.
     *
     * @param int $rosterId
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>  $coverageShortages
     * @return void
     */
    private function insertCoverage(int $rosterId, array $coverageShortages): void
    {
        $rows = array_map(static fn (array $shortage): array => [
            'work_date' => $shortage['work_date']->toDateString(),
            'shift_id' => $shortage['shift_id'],
            'role_id' => $shortage['role_id'],
            'required' => $shortage['required'],
            'assigned' => $shortage['assigned'],
        ], $coverageShortages);

        $this->coverageShortages->insertMany($rosterId, $rows);
    }
}
