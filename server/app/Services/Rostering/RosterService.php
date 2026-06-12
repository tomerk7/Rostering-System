<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use App\Models\Worker;
use App\Services\Rostering\Data\GenerationResult;
use App\Services\Rostering\Data\RosterSlot;
use App\Services\Rostering\Data\RosterWorker;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * Loads, persists, generates, and reports on saved rosters for the HTTP API.
 */
final readonly class RosterService
{
    /**
     * Constructor.
     *
     * @param RosterGenerator $generator
     * @param RosteringEngine $engine
     * @return void
     */
    public function __construct(
        private RosterGenerator $generator,
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
     * Generate a roster synchronously and persist it, replacing any existing
     * roster for the same month.
     *
     * @param int $year
     * @param int $month
     * @param int $userId
     * @return Roster
     *
     * @throws Exception
     */
    public function store(int $year, int $month, int $userId): Roster
    {
        $result = $this->generator->generate($year, $month);

        return $this->saveGenerationResult($result, $userId);
    }

    /**
     * Persist a generated roster, replacing any existing roster for the same month.
     */
    public function saveGenerationResult(GenerationResult $result, int $userId): Roster
    {
        return DB::transaction(function () use ($result, $userId): Roster {
            Roster::query()
                ->forPeriod($result->year, $result->month)
                ->delete();

            return $this->persistGenerationResult($result, $userId);
        });
    }

    /**
     * Regenerate assignments for an existing roster, keeping the same roster id.
     *
     * @param Roster $roster
     * @return Roster
     * @throws Exception
     */
    public function regenerate(Roster $roster): Roster
    {
        $result = $this->generator->generate($roster->year, $roster->month);

        return DB::transaction(function () use ($roster, $result): Roster {
            $roster->assignments()->delete();

            $now = Carbon::now();
            $roster->update([
                'generated_at' => $now,
                'published_at' => $now,
            ]);

            $this->insertAssignments($roster, $result->assignments);

            return $roster->fresh();
        });
    }

    /**
     * Delete a roster.
     *
     * @param Roster $roster
     * @return void
     */
    public function delete(Roster $roster): void
    {
        $roster->delete();
    }

    /**
     * Save a generated roster with its assignments.
     */
    private function persistGenerationResult(GenerationResult $result, int $createdBy): Roster
    {
        $now = Carbon::now();

        $roster = Roster::query()->create([
            'year' => $result->year,
            'month' => $result->month,
            'generated_at' => $now,
            'published_at' => $now,
            'created_by' => $createdBy,
        ]);

        $this->insertAssignments($roster, $result->assignments);

        return $roster;
    }

    /**
     * Bulk-insert the generation assignments for a roster.
     *
     * Uses a single insert for the high-volume fact table; timestamps and the
     * date string are set explicitly because insert() bypasses Eloquent casts.
     *
     * @param  list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
     */
    private function insertAssignments(Roster $roster, array $assignments): void
    {
        if ($assignments === []) {
            return;
        }

        $now = Carbon::now();
        $rosterId = $roster->getKey();

        $rows = array_map(
            static fn (array $assignment): array => [
                'roster_id' => $rosterId,
                'worker_id' => $assignment['worker_id'],
                'shift_id' => $assignment['shift_id'],
                'work_date' => $assignment['work_date']->toDateString(),
                'source' => $assignment['source'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $assignments,
        );

        RosterAssignment::query()->insert($rows);
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
            $workerId = (string) $assignment->worker_id;

            if (! isset($workers[$workerId])) {
                continue;
            }

            $workers[$workerId]->assignedHours +=
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
     * @param  list<array{worker_id: string, min_hours: int, scheduled_hours: int}>  $hoursShortfalls
     * @return array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>}
     */
    private function reports(array $coverageShortages, array $hoursShortfalls): array
    {
        $lookups = $this->loadReportLookups($coverageShortages, $hoursShortfalls);

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
     * @return array<string, RosterWorker>
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
                $workers[(string) $worker->israeli_id] = new RosterWorker(
                    roleId: (int) $worker->role_id,
                    minHours: (int) $worker->contract->min_monthly_hours,
                );
            });

        foreach ($assignments as $assignment) {
            $worker = $assignment->worker;

            if ($worker === null || isset($workers[(string) $worker->israeli_id])) {
                continue;
            }

            $workers[(string) $worker->israeli_id] = new RosterWorker(
                roleId: (int) $worker->role_id,
                minHours: (int) ($worker->contract?->min_monthly_hours ?? 0),
            );
        }

        return $workers;
    }

    /**
     * Expand the roster month into every staffing slot from shift_role_requirements.
     *
     * @return list<RosterSlot>
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
                $slots[] = new RosterSlot(
                    workDate: $date,
                    shiftId: (int) $requirement->shift_id,
                    roleId: (int) $requirement->role_id,
                    requiredCount: (int) $requirement->required_count,
                    durationHours: (int) $requirement->shift->duration_hours,
                );
            }
        }

        return $slots;
    }

    /**
     * Enrich a coverage shortage with the shift and role names.
     *
     * @param  array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}  $shortage
     * @param  array{worker_names: SupportCollection<int, string>, shift_codes: SupportCollection<int, string>, role_names: SupportCollection<int, string>}  $lookups
     * @return array<string, mixed>
     */
    private function enrichCoverageShortage(array $shortage, array $lookups): array
    {
        return [
            'work_date' => $shortage['work_date']->toDateString(),
            'shift_id' => $shortage['shift_id'],
            'shift_code' => $lookups['shift_codes']->get($shortage['shift_id']),
            'role_id' => $shortage['role_id'],
            'role_name' => $lookups['role_names']->get($shortage['role_id']),
            'required' => $shortage['required'],
            'assigned' => $shortage['assigned'],
            'missing' => $shortage['required'] - $shortage['assigned'],
        ];
    }

    /**
     * Enrich a hours shortfall with the worker name.
     *
     * @param  array{worker_id: string, min_hours: int, scheduled_hours: int}  $shortfall
     * @param  array{worker_names: SupportCollection<string, string>, shift_codes: SupportCollection<int, string>, role_names: SupportCollection<int, string>}  $lookups
     * @return array<string, mixed>
     */
    private function enrichHoursShortfall(array $shortfall, array $lookups): array
    {
        return [
            'worker_id' => $shortfall['worker_id'],
            'worker_name' => $lookups['worker_names']->get($shortfall['worker_id']),
            'min_hours' => $shortfall['min_hours'],
            'scheduled_hours' => $shortfall['scheduled_hours'],
            'shortfall_hours' => $shortfall['min_hours'] - $shortfall['scheduled_hours'],
        ];
    }

    /**
     * Load the labels referenced by the reports, keyed by model id.
     *
     * @param  list<array{shift_id: int, role_id: int}>  $coverageShortages
     * @param  list<array{worker_id: string}>  $hoursShortfalls
     * @return array{
     *     worker_names: SupportCollection<string, string>,
     *     shift_codes: SupportCollection<int, string>,
     *     role_names: SupportCollection<int, string>
     * }
     */
    private function loadReportLookups(array $coverageShortages, array $hoursShortfalls): array
    {
        $workerIds = array_values(array_unique(array_column($hoursShortfalls, 'worker_id')));
        $shiftIds = array_values(array_unique(array_column($coverageShortages, 'shift_id')));
        $roleIds = array_values(array_unique(array_column($coverageShortages, 'role_id')));

        return [
            'worker_names' => $workerIds === []
                ? collect()
                : Worker::query()->whereKey($workerIds)->pluck('full_name', 'israeli_id'),
            'shift_codes' => $shiftIds === []
                ? collect()
                : Shift::query()->whereKey($shiftIds)->pluck('code', 'id'),
            'role_names' => $roleIds === []
                ? collect()
                : Role::query()->whereKey($roleIds)->pluck('name', 'id'),
        ];
    }
}
