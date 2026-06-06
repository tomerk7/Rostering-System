<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Exceptions\Rostering\RosterStatusException;
use App\Jobs\GenerateRosterJob;
use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterGeneration;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Loads, persists, generates, and reports on saved rosters for the HTTP API.
 */
final readonly class RosterService
{
    /**
     * Constructor.
     *
     * @param RosterGenerator $generator
     * @param RosterPersister $persister
     * @param RosteringEngine $engine
     * @return void
     */
    public function __construct(
        private RosterGenerator $generator,
        private RosterPersister $persister,
        private RosteringEngine $engine,
    ) {}

    /**
     * List saved rosters with assignment counts.
     *
     * @return Collection<int, Roster>
     */
    public function list(): Collection
    {
        return Roster::query()
            ->withCount('assignments')
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Load one roster with enriched assignments, reports, and summary,
     * optionally filtering the listed assignments by date or shift.
     *
     * @param Roster $roster
     * @param string|null $date
     * @param int|null $shiftId
     * @return Roster
     */
    public function loadDetails(Roster $roster, ?string $date = null, ?int $shiftId = null): Roster
    {
        $assignmentsQuery = $roster->assignments()
            ->with(['worker.role', 'shift'])
            ->orderBy('work_date')
            ->orderBy('shift_id')
            ->orderBy('worker_id');

        if (filled($date)) {
            $assignmentsQuery->whereDate('work_date', $date);
        }

        if ($shiftId) {
            $assignmentsQuery->where('shift_id', $shiftId);
        }

        $roster->setRelation('assignments', $assignmentsQuery->get());
        $roster->setAttribute('assignments_count', $roster->assignments()->count());
        $roster->loadMissing('creator');

        $report = $this->buildReport($roster);
        $roster->setAttribute('reports', $report['reports']);
        $roster->setAttribute('summary', $report['summary']);

        return $roster;
    }

    /**
     * Create a generation record and dispatch the queued run.
     *
     * @param int $year
     * @param int $month
     * @param int $userId
     * @return RosterGeneration
     */
    public function queueGeneration(int $year, int $month, int $userId): RosterGeneration
    {
        $generation = RosterGeneration::query()->create([
            'uuid' => (string) Str::uuid(),
            'year' => $year,
            'month' => $month,
            'status' => 'queued',
            'requested_by' => $userId,
        ]);

        GenerateRosterJob::dispatch($generation->uuid);

        return $generation;
    }

    /**
     * Run a queued generation and persist the result as a draft roster.
     *
     * @param string $uuid
     * @return void
     *
     * @throws Exception
     */
    public function processGeneration(string $uuid): void
    {
        $generation = RosterGeneration::query()->where('uuid', $uuid)->firstOrFail();

        $generation->update([
            'status' => 'processing',
            'started_at' => Carbon::now(),
        ]);

        $result = $this->generator->generate($generation->year, $generation->month);

        DB::transaction(function () use ($generation, $result): void {
            $roster = $this->persister->save($result, (int) $generation->requested_by);

            $generation->update([
                'status' => 'completed',
                'roster_id' => $roster->getKey(),
                'completed_at' => Carbon::now(),
            ]);
        });
    }

    /**
     * Remove a failed generation tracker.
     *
     * @param string $uuid
     * @return void
     */
    public function deleteGeneration(string $uuid): void
    {
        RosterGeneration::query()
            ->where('uuid', $uuid)
            ->delete();
    }

    /**
     * Publish a draft roster, superseding any previously published roster for the month.
     *
     * @param Roster $roster
     * @return Roster
     * @throws RosterStatusException
     */
    public function publish(Roster $roster): Roster
    {
        return $this->persister->publish($roster);
    }

    /**
     * Delete a roster.
     *
     * @param Roster $roster
     * @return void
     */
    public function delete(Roster $roster): void
    {
        $this->persister->delete($roster);
    }

    /**
     * Build the enriched reports and summary payload for a saved roster.
     *
     * @param Roster $roster
     * @return array{reports: array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>}, summary: array<string, mixed>}
     */
    private function buildReport(Roster $roster): array
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

            $workers[$workerId]['assigned_hours'] +=
                (int) ($assignment->shift?->duration_hours ?? 0);

            $engineAssignments[] = [
                'worker_id' => $workerId,
                'shift_id' => (int) $assignment->shift_id,
                'work_date' => CarbonImmutable::parse(
                    (string) $assignment->work_date->toDateString(),
                ),
                'source' => $assignment->source->value,
            ];
        }

        $slots = $this->buildSlots($roster->year, $roster->month);

        $coverageShortages = $this->engine->validateCoverage(
            $slots,
            $engineAssignments,
            $workers,
        );

        $hoursShortfalls = $this->engine->reportHoursShortfalls($workers);

        return [
            'reports' => $this->reports($coverageShortages, $hoursShortfalls),
            'summary' => $this->summary(
                $assignments->count(),
                $coverageShortages,
                $hoursShortfalls,
            ),
        ];
    }

    /**
     * Build the enriched reports payload.
     *
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>  $coverageShortages
     * @param  list<array{worker_id: int, min_hours: int, scheduled_hours: int}>  $hoursShortfalls
     * @return array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>}
     */
    private function reports(array $coverageShortages, array $hoursShortfalls): array
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
    private function summary(int $assignmentCount, array $coverageShortages, array $hoursShortfalls): array
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
     * Build the worker state map keyed by id, covering every active worker with a
     * contract plus any worker referenced by the stored assignments.
     *
     * @param  Collection<int, \App\Models\RosterAssignment>  $assignments
     * @return array<int, array{role_id: int, min_hours: int, assigned_hours: int}>
     */
    private function buildWorkerState(Collection $assignments): array
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
     * @param  array{workers: SupportCollection<int, Worker>, shifts: SupportCollection<int, Shift>, roles: SupportCollection<int, Role>}  $lookups
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
     * @param  array{workers: SupportCollection<int, Worker>, shifts: SupportCollection<int, Shift>, roles: SupportCollection<int, Role>}  $lookups
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
     *     workers: SupportCollection<int, Worker>,
     *     shifts: SupportCollection<int, Shift>,
     *     roles: SupportCollection<int, Role>
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
