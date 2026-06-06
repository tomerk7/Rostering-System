<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\Roster;
use App\Models\ShiftRoleRequirement;
use App\Models\Worker;
use Carbon\CarbonImmutable;

/**
 * Recomputes the coverage and hours reports for a persisted roster from its
 * stored assignments, so saved rosters surface the same alerts as a preview
 * after manual edits.
 */
final readonly class RosterReportService
{
    /**
     * Constructor.
     *
     * @param RosteringEngine $engine
     * @param RosterReportPresenter $presenter
     * @return void
     */
    public function __construct(
        private RosteringEngine $engine,
        private RosterReportPresenter $presenter,
    ) {}

    /**
     * Build the enriched reports and summary payload for a saved roster.
     *
     * @param Roster $roster
     * @return array{reports: array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>}, summary: array<string, mixed>}
     */
    public function build(Roster $roster): array
    {
        [$coverageShortages, $hoursShortfalls, $assignmentCount] = $this->compute($roster);

        return [
            'reports' => $this->presenter->reports($coverageShortages, $hoursShortfalls),
            'summary' => $this->presenter->summary($assignmentCount, $coverageShortages, $hoursShortfalls),
        ];
    }

    /**
     * Compute the raw coverage shortages, hours shortfalls, and assignment count.
     *
     * @param Roster $roster
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: int}
     */
    private function compute(Roster $roster): array
    {
        $assignments = $roster->assignments()
            ->with(['worker', 'shift'])
            ->get();

        $workers = $this->buildWorkerState($assignments);
        $engineAssignments = [];

        foreach ($assignments as $assignment) {
            $workerId = (int) $assignment->worker_id;

            if (! isset($workers[$workerId])) {
                continue;
            }

            $workers[$workerId]['assigned_hours'] += (int) ($assignment->shift?->duration_hours ?? 0);

            $engineAssignments[] = [
                'worker_id' => $workerId,
                'shift_id' => (int) $assignment->shift_id,
                'work_date' => CarbonImmutable::parse((string) $assignment->work_date->toDateString()),
                'source' => $assignment->source->value,
            ];
        }

        $slots = $this->buildSlots($roster->year, $roster->month);

        return [
            $this->engine->validateCoverage($slots, $engineAssignments, $workers),
            $this->engine->reportHoursShortfalls($workers),
            $assignments->count(),
        ];
    }

    /**
     * Build the worker state map keyed by id, covering every active worker with a
     * contract plus any worker referenced by the stored assignments.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, \App\Models\RosterAssignment>  $assignments
     * @return array<int, array{role_id: int, min_hours: int, assigned_hours: int}>
     */
    private function buildWorkerState($assignments): array
    {
        $workers = [];

        Worker::query()
            ->active()
            ->whereHas('contract')
            ->with('contract')
            ->get()
            ->each(function (Worker $worker) use (&$workers): void {
                $workers[(int) $worker->id] = [
                    'role_id' => (int) $worker->role_id,
                    'min_hours' => (int) $worker->contract->min_monthly_hours,
                    'assigned_hours' => 0,
                ];
            });

        foreach ($assignments as $assignment) {
            $worker = $assignment->worker;

            if ($worker === null || isset($workers[(int) $worker->id])) {
                continue;
            }

            $workers[(int) $worker->id] = [
                'role_id' => (int) $worker->role_id,
                'min_hours' => (int) ($worker->contract?->min_monthly_hours ?? 0),
                'assigned_hours' => 0,
            ];
        }

        return $workers;
    }

    /**
     * Expand the roster month into every staffing slot from shift_role_requirements.
     *
     * @param  int  $year
     * @param  int  $month
     * @return list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required_count: int, duration_hours: int}>
     */
    private function buildSlots(int $year, int $month): array
    {
        $requirements = ShiftRoleRequirement::query()
            ->where('required_count', '>', 0)
            ->with('shift')
            ->orderBy('shift_id')
            ->orderBy('role_id')
            ->get();

        if ($requirements->isEmpty()) {
            return [];
        }

        $firstDay = CarbonImmutable::createFromDate($year, $month, 1)->startOfDay();
        $daysInMonth = $firstDay->daysInMonth;

        $slots = [];

        for ($day = 0; $day < $daysInMonth; $day++) {
            $date = $firstDay->addDays($day);

            foreach ($requirements as $requirement) {
                $slots[] = [
                    'work_date' => $date,
                    'shift_id' => (int) $requirement->shift_id,
                    'role_id' => (int) $requirement->role_id,
                    'required_count' => (int) $requirement->required_count,
                    'duration_hours' => (int) $requirement->shift->duration_hours,
                ];
            }
        }

        return $slots;
    }
}
