<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\RosterAlertType;
use App\Models\CoverageShortage;
use App\Models\Roster;
use App\Models\RosterAlert;
use App\Models\RosterAssignment;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Exception;
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
     * Recompute and persist coverage shortages and hours-shortfall alerts for every roster.
     *
     * @throws Exception
     */
    public function refreshAllReports(): void
    {
        Roster::query()
            ->orderBy('id')
            ->cursor()
            ->each(function (Roster $roster): void {
                $this->refreshReports($roster);
            });
    }

    /**
     * Recompute persisted worker alerts for every roster.
     *
     * @throws Exception
     */
    public function refreshAllWorkerAlerts(): void
    {
        Roster::query()
            ->orderBy('id')
            ->cursor()
            ->each(function (Roster $roster): void {
                DB::transaction(function () use ($roster): void {
                    $reports = $this->recomputeReports($roster);

                    $roster->alerts()->hoursShortfall()->delete();
                    $this->insertWorkerAlerts($roster, $reports['hoursShortfalls']);
                });
            });
    }

    /**
     * Recompute persisted coverage shortages for every roster.
     *
     * @throws Exception
     */
    public function refreshAllCoverageShortages(): void
    {
        Roster::query()
            ->orderBy('id')
            ->cursor()
            ->each(function (Roster $roster): void {
                DB::transaction(function () use ($roster): void {
                    $reports = $this->recomputeReports($roster, removeInvalidAssignments: true);

                    $roster->coverageShortages()->delete();
                    $this->insertCoverageShortages($roster, $reports['coverageShortages']);
                });
            });
    }

    /**
     * Recompute and persist coverage shortages and hours-shortfall alerts for a roster.
     *
     * @throws Exception
     */
    public function refreshReports(Roster $roster): void
    {
        DB::transaction(function () use ($roster): void {
            $reports = $this->recomputeReports($roster, removeInvalidAssignments: true);

            $roster->coverageShortages()->delete();
            $roster->alerts()->hoursShortfall()->delete();

            $this->insertCoverageShortages($roster, $reports['coverageShortages']);
            $this->insertWorkerAlerts($roster, $reports['hoursShortfalls']);
        });
    }

    /**
     * Persist the generation reports: coverage shortages and worker alerts.
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
                'worker_name' => $alert->worker?->full_name,
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
     * Build fresh reports from the roster's currently valid assignments.
     *
     * @return array{
     *     coverageShortages: list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>,
     *     hoursShortfalls: list<array{worker_id: string, min_hours: int, scheduled_hours: int}>
     * }
     * @throws Exception
     */
    private function recomputeReports(Roster $roster, bool $removeInvalidAssignments = false): array
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

        if ($removeInvalidAssignments && $invalidIds !== []) {
            RosterAssignment::query()->whereKey($invalidIds)->delete();
        }

        return $this->generator->recomputeReports(
            $roster->year,
            $roster->month,
            $savedAssignments,
        );
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

        if ($worker === null || ! $worker->is_active || $worker->contract === null) {
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
     * @param  list<array{worker_id: string, min_hours: int, scheduled_hours: int}>  $hoursShortfalls
     */
    private function insertWorkerAlerts(Roster $roster, array $hoursShortfalls): void
    {
        if ($hoursShortfalls === []) {
            return;
        }

        $now = Carbon::now();
        $rosterId = $roster->getKey();

        $rows = array_map(
            static fn (array $shortfall): array => [
                'roster_id' => $rosterId,
                'type' => RosterAlertType::HoursShortfall->value,
                'worker_id' => $shortfall['worker_id'],
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
