<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\RosterAlertType;
use App\Enums\RosterStatus;
use App\Jobs\GenerateRosterJob;
use App\Models\CoverageShortage;
use App\Models\Roster;
use App\Models\RosterAlert;
use App\Models\RosterAssignment;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loads, persists, generates, and reports on saved rosters for the HTTP API.
 */
final readonly class RosterService
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
     * optionally omitting or filtering the listed assignments.
     */
    public function loadDetails(
        Roster $roster,
        ?string $date = null,
        ?int $shiftId = null,
        bool $includeAssignments = true,
    ): Roster {
        if ($includeAssignments) {
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
        }

        $roster->setAttribute('assignments_count', $roster->assignments()->count());
        $roster->loadMissing('creator');

        $report = $this->loadReport($roster);
        $roster->setAttribute('reports', $report['reports']);
        $roster->setAttribute('summary', $report['summary']);

        return $roster;
    }

    /**
     * Generate a roster and persist it, replacing any existing roster for the same month.
     *
     * @throws Exception
     */
    public function store(int $year, int $month, int $userId): Roster
    {
        $result = $this->generator->generate($year, $month);

        return $this->saveGenerationResult($result, $userId);
    }

    /**
     * Queue creation of a roster for a month.
     */
    public function queueStore(int $year, int $month, int $userId): Roster
    {
        return DB::transaction(function () use ($year, $month, $userId): Roster {
            Roster::query()
                ->forPeriod($year, $month)
                ->delete();

            $roster = Roster::query()->create([
                'year' => $year,
                'month' => $month,
                'generated_at' => null,
                'published_at' => null,
                'created_by' => $userId,
                'status' => RosterStatus::Processing,
            ]);

            GenerateRosterJob::dispatch((int) $roster->getKey());

            return $roster->fresh();
        });
    }

    /**
     * Queue regeneration of an existing roster.
     */
    public function queueRegeneration(Roster $roster): Roster
    {
        $roster->update(['status' => RosterStatus::Processing]);

        GenerateRosterJob::dispatch((int) $roster->getKey());

        return $roster->fresh();
    }

    /**
     * Process a queued roster generation.
     *
     * @throws Exception
     */
    public function processGeneration(int $rosterId): void
    {
        $roster = Roster::query()->findOrFail($rosterId);

        if ($roster->assignments()->exists()) {
            $this->regenerate($roster);
        } else {
            $this->fillNewRoster($roster);
        }

        $roster->update(['status' => RosterStatus::Ready]);
    }

    /**
     * Record a failed queued roster generation.
     */
    public function markGenerationFailed(Roster $roster): void
    {
        $roster->update(['status' => RosterStatus::Failed]);
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
     * @throws Exception
     */
    public function regenerate(Roster $roster): Roster
    {
        $result = $this->generator->generate($roster->year, $roster->month);

        return DB::transaction(function () use ($roster, $result): Roster {
            $roster->assignments()->delete();
            $roster->alerts()->delete();
            $roster->coverageShortages()->delete();

            $now = Carbon::now();
            $roster->update([
                'generated_at' => $now,
                'published_at' => $now,
            ]);

            $this->insertAssignments($roster, $result->assignments);
            $this->insertAlerts($roster, $result);

            return $roster->fresh();
        });
    }

    /**
     * Delete a roster.
     */
    public function delete(Roster $roster): void
    {
        $roster->delete();
    }

    /**
     * Recompute and persist coverage shortages and hours-shortfall alerts for a roster.
     *
     * @throws Exception
     */
    public function refreshReports(Roster $roster): void
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

        $reports = $this->generator->recomputeReports($roster->year, $roster->month, $savedAssignments);

        DB::transaction(function () use ($roster, $reports): void {
            $roster->coverageShortages()->delete();
            $roster->alerts()->hoursShortfall()->delete();

            $this->insertCoverageShortages($roster, $reports['coverageShortages']);
            $this->insertWorkerAlerts($roster, $reports['hoursShortfalls']);
        });
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
            'status' => RosterStatus::Ready,
        ]);

        $this->insertAssignments($roster, $result->assignments);
        $this->insertAlerts($roster, $result);

        return $roster;
    }

    /**
     * Generate and persist assignments into a queued roster stub.
     *
     * @throws Exception
     */
    private function fillNewRoster(Roster $roster): void
    {
        $result = $this->generator->generate($roster->year, $roster->month);
        $now = Carbon::now();

        $roster->update([
            'generated_at' => $now,
            'published_at' => $now,
        ]);

        $this->insertAssignments($roster, $result->assignments);
        $this->insertAlerts($roster, $result);
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
     * Persist the generation reports: coverage shortages and worker alerts.
     */
    private function insertAlerts(Roster $roster, GenerationResult $result): void
    {
        $this->insertCoverageShortages($roster, $result->coverageShortages);
        $this->insertWorkerAlerts($roster, $result->hoursShortfalls);
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

    /**
     * Load the persisted reports and summary payload for a saved roster.
     *
     * @return array{reports: array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>}, summary: array<string, mixed>}
     */
    private function loadReport(Roster $roster): array
    {
        $coverageShortages = $roster->coverageShortages()
            ->with(['shift', 'role'])
            ->orderBy('work_date')
            ->orderBy('shift_id')
            ->orderBy('role_id')
            ->get()
            ->map(fn (CoverageShortage $shortage): array => $this->formatCoverageShortage($shortage))
            ->values()
            ->all();

        $hoursShortfalls = $roster->alerts()
            ->hoursShortfall()
            ->with('worker')
            ->orderBy('worker_id')
            ->get()
            ->map(fn (RosterAlert $alert): array => $this->formatHoursShortfall($alert))
            ->values()
            ->all();

        return [
            'reports' => [
                'coverage_shortages' => $coverageShortages,
                'hours_shortfalls' => $hoursShortfalls,
            ],
            'summary' => $this->summary(
                $roster->assignments_count,
                $coverageShortages,
                $hoursShortfalls,
            ),
        ];
    }

    /**
     * Format a persisted coverage shortage for the API.
     *
     * @return array<string, mixed>
     */
    private function formatCoverageShortage(CoverageShortage $shortage): array
    {
        return [
            'work_date' => $shortage->work_date?->toDateString(),
            'shift_id' => $shortage->shift_id,
            'shift_code' => $shortage->shift?->code,
            'role_id' => $shortage->role_id,
            'role_name' => $shortage->role?->name,
            'required' => $shortage->required_count,
            'assigned' => $shortage->assigned_count,
            'missing' => $shortage->required_count - $shortage->assigned_count,
        ];
    }

    /**
     * Format a persisted hours-shortfall alert for the API.
     *
     * @return array<string, mixed>
     */
    private function formatHoursShortfall(RosterAlert $alert): array
    {
        return [
            'worker_id' => $alert->worker_id,
            'worker_name' => $alert->worker?->full_name,
            'min_hours' => $alert->min_hours,
            'scheduled_hours' => $alert->scheduled_hours,
            'shortfall_hours' => $alert->min_hours - $alert->scheduled_hours,
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
}
