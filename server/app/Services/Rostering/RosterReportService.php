<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\Role;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Recomputes coverage and hours reports for persisted rosters and builds the
 * enriched API payloads shared by roster previews and saved rosters.
 */
final readonly class RosterReportService
{
    /**
     * Constructor.
     *
     * @param RosteringEngine $engine
     * @return void
     */
    public function __construct(
        private RosteringEngine $engine,
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
            'reports' => $this->reports($coverageShortages, $hoursShortfalls),
            'summary' => $this->summary($assignmentCount, $coverageShortages, $hoursShortfalls),
        ];
    }

    /**
     * Build the enriched reports payload.
     *
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>  $coverageShortages
     * @param  list<array{worker_id: int, min_hours: int, scheduled_hours: int}>  $hoursShortfalls
     * @return array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>}
     */
    public function reports(array $coverageShortages, array $hoursShortfalls): array
    {
        $lookups = $this->warmLookups($coverageShortages, $hoursShortfalls);

        return [
            'coverage_shortages' => array_map(
                fn (array $shortage): array => $this->enrichCoverageShortage($shortage, $lookups),
                $coverageShortages,
            ),
            'hours_shortfalls' => array_map(
                fn (array $shortfall): array => $this->enrichHoursShortfall($shortfall, $lookups),
                $hoursShortfalls,
            ),
        ];
    }

    /**
     * Build the roster summary counters.
     *
     * @param  list<array<string, mixed>>  $coverageShortages
     * @param  list<array<string, mixed>>  $hoursShortfalls
     * @return array<string, mixed>
     */
    public function summary(int $assignmentCount, array $coverageShortages, array $hoursShortfalls): array
    {
        return [
            'assignment_count' => $assignmentCount,
            'coverage_shortage_count' => count($coverageShortages),
            'hours_shortfall_count' => count($hoursShortfalls),
            'has_coverage_shortages' => count($coverageShortages),
            'has_hours_shortfalls' => count($hoursShortfalls),
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

    /**
     * Enrich a coverage shortage with the shift and role names.
     *
     * @param  array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}  $shortage
     * @param  array{workers: Collection<int, Worker>, shifts: Collection<int, Shift>, roles: Collection<int, Role>}  $lookups
     * @return array<string, mixed>
     */
    private function enrichCoverageShortage(array $shortage, array $lookups): array
    {
        $shift = $lookups['shifts']->get($shortage['shift_id']);
        $role = $lookups['roles']->get($shortage['role_id']);

        return [
            'work_date' => $shortage['work_date']->toDateString(),
            'shift_id' => $shortage['shift_id'],
            'shift_code' => $shift?->code,
            'role_id' => $shortage['role_id'],
            'role_name' => $role?->name,
            'required' => $shortage['required'],
            'assigned' => $shortage['assigned'],
            'missing' => $shortage['required'] - $shortage['assigned'],
        ];
    }

    /**
     * Enrich a hours shortfall with the worker name.
     *
     * @param  array{worker_id: int, min_hours: int, scheduled_hours: int}  $shortfall
     * @param  array{workers: Collection<int, Worker>, shifts: Collection<int, Shift>, roles: Collection<int, Role>}  $lookups
     * @return array<string, mixed>
     */
    private function enrichHoursShortfall(array $shortfall, array $lookups): array
    {
        $worker = $lookups['workers']->get($shortfall['worker_id']);

        return [
            'worker_id' => $shortfall['worker_id'],
            'worker_name' => $worker?->full_name,
            'min_hours' => $shortfall['min_hours'],
            'scheduled_hours' => $shortfall['scheduled_hours'],
            'shortfall_hours' => $shortfall['min_hours'] - $shortfall['scheduled_hours'],
        ];
    }

    /**
     * Resolve the workers, shifts, and roles referenced by the reports.
     *
     * @param  list<array{shift_id: int, role_id: int}>  $coverageShortages
     * @param  list<array{worker_id: int}>  $hoursShortfalls
     * @return array{
     *     workers: Collection<int, Worker>,
     *     shifts: Collection<int, Shift>,
     *     roles: Collection<int, Role>
     * }
     */
    private function warmLookups(array $coverageShortages, array $hoursShortfalls): array
    {
        $workerIds = array_unique(array_column($hoursShortfalls, 'worker_id'));
        $shiftIds = array_unique(array_column($coverageShortages, 'shift_id'));
        $roleIds = array_unique(array_column($coverageShortages, 'role_id'));

        return [
            'workers' => Worker::query()->whereIn('id', $workerIds)->get()->keyBy('id'),
            'shifts' => Shift::query()->whereIn('id', $shiftIds)->get()->keyBy('id'),
            'roles' => Role::query()->whereIn('id', $roleIds)->get()->keyBy('id'),
        ];
    }
}
