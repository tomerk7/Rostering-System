<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\RosterStatus;
use App\Jobs\GenerateRosterJob;
use App\Models\Roster;
use App\Models\RosterAssignment;
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
        private RosterReportService $reportService,
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

        $report = $this->reportService->loadReport($roster);
        $roster->setAttribute('reports', $report['reports']);
        $roster->setAttribute('summary', $report['summary']);

        return $roster;
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
                'period_start' => Carbon::create($year, $month, 1)->toDateString(),
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
     * 
     * @param Roster $roster
     * @return Roster
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
     * Regenerate assignments for an existing roster, keeping the same roster id.
     *
     * @throws Exception
     */
    private function regenerate(Roster $roster): Roster
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
            $this->reportService->insertAlerts($roster, $result);

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
        $this->reportService->insertAlerts($roster, $result);
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
}
