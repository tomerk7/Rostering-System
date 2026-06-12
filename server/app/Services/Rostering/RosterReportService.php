<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\RosterAlertType;
use App\Models\CoverageShortage;
use App\Models\Roster;
use App\Models\RosterAlert;
use App\Models\RosterAssignment;
use App\Models\Worker;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes, persists, loads, and formats roster coverage shortages and hours-shortfall alerts.
 */
final readonly class RosterReportService
{
    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct(
        private RosterGenerator $generator,
    ) {}

    /**
     * Refresh alerts for changed workers and recompute coverage only in rosters
     * where those workers had assignments, scoped to current and future months.
     *
     * @param  list<string>  $workerIds
     *
     * @throws Exception
     */
    public function refreshReportsForWorkers(array $workerIds): void
    {
        if ($workerIds === []) {
            return;
        }

        $workerIds = array_values(array_unique($workerIds));

        $this->upcomingRostersQuery()
            ->orderBy('id')
            ->cursor()
            ->each(function (Roster $roster) use ($workerIds): void {
                DB::transaction(function () use ($roster, $workerIds): void {
                    $hadAssignments = $this->invalidateChangedWorkerAssignments($roster, $workerIds);

                    $this->rebuildWorkerAlerts($roster, $workerIds);

                    if ($hadAssignments) {
                        $this->recomputeCoverage($roster);
                    }
                });
            });
    }

    /**
     * Recompute coverage shortages for every current and future roster.
     *
     * @throws Exception
     */
    public function refreshCoverageForUpcomingRosters(): void
    {
        $this->upcomingRostersQuery()
            ->orderBy('id')
            ->cursor()
            ->each(function (Roster $roster): void {
                DB::transaction(function () use ($roster): void {
                    $this->recomputeCoverage($roster);
                });
            });
    }

    /**
     * Recompute coverage shortages for the given rosters.
     *
     * @param  list<int>  $rosterIds
     *
     * @throws Exception
     */
    public function refreshCoverageForRosters(array $rosterIds): void
    {
        if ($rosterIds === []) {
            return;
        }

        Roster::query()
            ->whereIn('id', $rosterIds)
            ->orderBy('id')
            ->cursor()
            ->each(function (Roster $roster): void {
                DB::transaction(function () use ($roster): void {
                    $this->recomputeCoverage($roster);
                });
            });
    }

    /**
     * Remove alerts for the given workers from current and future rosters only,
     * preserving past alerts as history.
     *
     * @param  list<string>  $workerIds
     */
    public function removeUpcomingAlertsForWorkers(array $workerIds): void
    {
        if ($workerIds === []) {
            return;
        }

        RosterAlert::query()
            ->whereIn('roster_id', $this->upcomingRostersQuery()->select('id'))
            ->whereIn('worker_id', $workerIds)
            ->delete();
    }

    /**
     * Remove every alert from current and future rosters only,
     * preserving past alerts as history.
     */
    public function removeUpcomingAlerts(): void
    {
        RosterAlert::query()
            ->whereIn('roster_id', $this->upcomingRostersQuery()->select('id'))
            ->delete();
    }

    /**
     * Recompute and persist coverage shortages and hours-shortfall alerts for a roster.
     *
     * @throws Exception
     */
    public function refreshReports(Roster $roster): void
    {
        DB::transaction(function () use ($roster): void {
            $reports = $this->recomputeReports($roster);

            $roster->coverageShortages()->delete();
            $roster->alerts()->hoursShortfall()->delete();

            $this->insertCoverageShortages($roster, $reports['coverageShortages']);
            $this->insertWorkerAlerts($roster, $reports['hoursShortfalls']);
        });
    }

    /**
     * Persist the generation reports: coverage shortages and worker alerts.
     * 
     * @param Roster $roster
     * @param GenerationResult $result
     * @return void
     */
    public function insertAlerts(Roster $roster, GenerationResult $result): void
    {
        $this->insertCoverageShortages($roster, $result->coverageShortages);
        $this->insertWorkerAlerts($roster, $result->hoursShortfalls);
    }

    /**
     * Load the persisted reports and summary payload for a saved roster.
     *
     * @return array{reports: array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>}, summary: array<string, mixed>}
     * @throws Exception
     */
    public function loadReport(Roster $roster): array
    {
        $coverageShortages = $roster->coverageShortages()
            ->with(['shift', 'role'])
            ->orderBy('work_date')
            ->orderBy('shift_id')
            ->orderBy('role_id')
            ->get()
            ->map(static fn (CoverageShortage $shortage): array => [
                'work_date' => $shortage->work_date?->toDateString(),
                'shift_id' => $shortage->shift_id,
                'shift_code' => $shortage->shift?->code,
                'role_id' => $shortage->role_id,
                'role_name' => $shortage->role?->name,
                'required' => $shortage->required_count,
                'assigned' => $shortage->assigned_count,
                'missing' => $shortage->required_count - $shortage->assigned_count,
            ])
            ->values()
            ->all();

        $hoursShortfalls = $roster->alerts()
            ->hoursShortfall()
            ->with('worker')
            ->orderBy('worker_id')
            ->get()
            ->map(static fn (RosterAlert $alert): array => [
                'worker_id' => $alert->worker_id,
                'worker_name' => $alert->worker?->full_name ?? $alert->worker_name,
                'min_hours' => $alert->min_hours,
                'scheduled_hours' => $alert->scheduled_hours,
                'shortfall_hours' => $alert->min_hours - $alert->scheduled_hours,
            ])
            ->values()
            ->all();

        return [
            'reports' => [
                'coverage_shortages' => $coverageShortages,
                'hours_shortfalls' => $hoursShortfalls,
            ],
            'summary' => [
                'assignment_count' => $roster->assignments_count,
                'coverage_shortage_count' => count($coverageShortages),
                'hours_shortfall_count' => count($hoursShortfalls),
                'has_coverage_shortages' => count($coverageShortages),
                'has_hours_shortfalls' => count($hoursShortfalls),
            ],
        ];
    }

    /**
     * Build fresh reports from the roster's currently valid assignments,
     * deleting assignments that no longer match worker availability.
     *
     * @return array{
     *     coverageShortages: list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>,
     *     hoursShortfalls: list<array{worker_id: string, min_hours: int, scheduled_hours: int}>
     * }
     * @throws Exception
     */
    private function recomputeReports(Roster $roster): array
    {
        $assignments = $roster->assignments()
            ->with('worker.contract.availability')
            ->orderBy('work_date')
            ->orderBy('shift_id')
            ->orderBy('worker_id')
            ->get();

        $savedAssignments = [];
        $invalidIds = [];

        foreach ($assignments as $assignment) {
            $workDate = CarbonImmutable::parse($assignment->work_date->toDateString())->startOfDay();

            if ($this->assignmentIsStillValid($assignment, $workDate)) {
                $savedAssignments[] = [
                    'worker_id' => (string) $assignment->worker_id,
                    'shift_id' => (int) $assignment->shift_id,
                    'work_date' => $workDate,
                ];
            } else {
                $invalidIds[] = $assignment->getKey();
            }
        }

        if ($invalidIds !== []) {
            RosterAssignment::query()->whereKey($invalidIds)->delete();
        }

        return $this->generator->recomputeReports(
            $roster->year,
            $roster->month,
            $savedAssignments,
        );
    }

    /**
     * Scope rosters from the current month onward.
     *
     * @return Builder<Roster>
     */
    private function upcomingRostersQuery(): Builder
    {
        return Roster::query()
            ->whereDate('period_start', '>=', Carbon::now()->startOfMonth()->toDateString());
    }

    /**
     * Delete assignments for changed workers that no longer match availability or active status.
     *
     * @param  list<string>  $workerIds
     */
    private function invalidateChangedWorkerAssignments(Roster $roster, array $workerIds): bool
    {
        $assignments = $roster->assignments()
            ->whereIn('worker_id', $workerIds)
            ->with('worker.contract.availability')
            ->get();

        if ($assignments->isEmpty()) {
            return false;
        }

        $invalidIds = [];

        foreach ($assignments as $assignment) {
            $workDate = CarbonImmutable::parse($assignment->work_date->toDateString())->startOfDay();

            if (! $this->assignmentIsStillValid($assignment, $workDate)) {
                $invalidIds[] = $assignment->getKey();
            }
        }

        if ($invalidIds !== []) {
            RosterAssignment::query()->whereKey($invalidIds)->delete();
        }

        return true;
    }

    /**
     * Rebuild hours-shortfall alerts for the given workers in a roster.
     *
     * @param  list<string>  $workerIds
     */
    private function rebuildWorkerAlerts(Roster $roster, array $workerIds): void
    {
        $roster->alerts()
            ->hoursShortfall()
            ->whereIn('worker_id', $workerIds)
            ->delete();

        $workers = Worker::query()
            ->whereIn('israeli_id', $workerIds)
            ->with('contract')
            ->get();

        if ($workers->isEmpty()) {
            return;
        }

        /** @var array<string, int> $scheduledHoursByWorker */
        $scheduledHoursByWorker = $roster->assignments()
            ->whereIn('worker_id', $workerIds)
            ->join('shifts', 'shifts.id', '=', 'roster_assignments.shift_id')
            ->selectRaw('roster_assignments.worker_id, SUM(shifts.duration_hours) AS scheduled_hours')
            ->groupBy('roster_assignments.worker_id')
            ->pluck('scheduled_hours', 'worker_id')
            ->map(static fn (mixed $hours): int => (int) $hours)
            ->all();

        $shortfalls = [];

        foreach ($workers as $worker) {
            if (! $worker->is_active || $worker->contract === null) {
                continue;
            }

            $scheduledHours = $scheduledHoursByWorker[$worker->israeli_id] ?? 0;
            $minHours = (int) $worker->contract->min_monthly_hours;

            if ($scheduledHours < $minHours) {
                $shortfalls[] = [
                    'worker_id' => $worker->israeli_id,
                    'min_hours' => $minHours,
                    'scheduled_hours' => $scheduledHours,
                ];
            }
        }

        $this->insertWorkerAlerts($roster, $shortfalls);
    }

    /**
     * Recompute and persist coverage shortages from the roster's current assignments.
     *
     * @throws Exception
     */
    private function recomputeCoverage(Roster $roster): void
    {
        $savedAssignments = $roster->assignments()
            ->orderBy('work_date')
            ->orderBy('shift_id')
            ->orderBy('worker_id')
            ->get()
            ->map(static fn (RosterAssignment $assignment): array => [
                'worker_id' => (string) $assignment->worker_id,
                'shift_id' => (int) $assignment->shift_id,
                'work_date' => CarbonImmutable::parse($assignment->work_date->toDateString())->startOfDay(),
            ])
            ->all();

        $reports = $this->generator->recomputeReports(
            $roster->year,
            $roster->month,
            $savedAssignments,
        );

        $roster->coverageShortages()->delete();
        $this->insertCoverageShortages($roster, $reports['coverageShortages']);
    }

    /**
     * Check whether an assignment still matches the worker's current availability.
     * 
     * @param RosterAssignment $assignment
     * @param CarbonImmutable $workDate
     * @return bool
     */
    private function assignmentIsStillValid(RosterAssignment $assignment, CarbonImmutable $workDate): bool
    {
        $worker = $assignment->worker;

        if ($worker === null || $worker->trashed() || ! $worker->is_active || $worker->contract === null) {
            return false;
        }

        $shiftId = (int) $assignment->shift_id;

        foreach ($worker->contract->availability as $slot) {
            if ((int) $slot->day_of_week === $workDate->dayOfWeek
                && (int) $slot->shift_id === $shiftId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Bulk-insert the coverage shortages (understaffed slots) for a roster.
     *
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>  $coverageShortages
     */
    private function insertCoverageShortages(Roster $roster, array $coverageShortages): void
    {
        if ($coverageShortages === []) {
            return;
        }

        $now = Carbon::now();
        $rosterId = $roster->getKey();

        $rows = array_map(
            static fn (array $shortage): array => [
                'roster_id' => $rosterId,
                'work_date' => $shortage['work_date']->toDateString(),
                'shift_id' => $shortage['shift_id'],
                'role_id' => $shortage['role_id'],
                'required_count' => $shortage['required'],
                'assigned_count' => $shortage['assigned'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $coverageShortages,
        );

        CoverageShortage::query()->insert($rows);
    }

    /**
     * Bulk-insert the worker alerts (hours shortfalls) for a roster.
     *
     * The worker's name is denormalized onto each alert so the row remains a
     * readable historical record even after the worker is deleted.
     *
     * @param  list<array{worker_id: string, min_hours: int, scheduled_hours: int}>  $hoursShortfalls
     */
    private function insertWorkerAlerts(Roster $roster, array $hoursShortfalls): void
    {
        if ($hoursShortfalls === []) {
            return;
        }

        /** @var array<string, string> $workerNames */
        $workerNames = Worker::query()
            ->whereIn('israeli_id', array_column($hoursShortfalls, 'worker_id'))
            ->pluck('full_name', 'israeli_id')
            ->all();

        $now = Carbon::now();
        $rosterId = $roster->getKey();

        $rows = array_map(
            static fn (array $shortfall): array => [
                'roster_id' => $rosterId,
                'type' => RosterAlertType::HoursShortfall->value,
                'worker_id' => $shortfall['worker_id'],
                'worker_name' => $workerNames[$shortfall['worker_id']] ?? null,
                'min_hours' => $shortfall['min_hours'],
                'scheduled_hours' => $shortfall['scheduled_hours'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $hoursShortfalls,
        );

        RosterAlert::query()->insert($rows);
    }
}
