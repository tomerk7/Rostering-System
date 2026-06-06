<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Exceptions\Rostering\RosterStatusException;
use App\Jobs\GenerateRosterJob;
use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterGeneration;
use App\Models\Shift;
use App\Models\Worker;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Loads, persists, and generates saved rosters for the HTTP API.
 */
final readonly class RosterService
{
    /**
     * Constructor.
     *
     * @param RosterGenerator $generator
     * @param RosterPersister $persister
     * @param RosterReportService $reportService
     * @return void
     */
    public function __construct(
        private RosterGenerator $generator,
        private RosterPersister $persister,
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

        if ($date !== null && $date !== '') {
            $assignmentsQuery->whereDate('work_date', $date);
        }

        if ($shiftId !== null) {
            $assignmentsQuery->where('shift_id', $shiftId);
        }

        $roster->setRelation('assignments', $assignmentsQuery->get());
        $roster->setAttribute('assignments_count', $roster->assignments()->count());
        $roster->loadMissing('creator');

        $report = $this->reportService->build($roster);
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
     * Run a queued generation and persist its enriched preview payload.
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
        $payload = $this->buildPreviewPayload($result);

        $generation->update([
            'status' => 'completed',
            'assignments' => $payload['assignments'],
            'coverage_shortages' => $payload['reports']['coverage_shortages'],
            'hours_shortfalls' => $payload['reports']['hours_shortfalls'],
            'summary' => $payload['summary'],
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Record a failed generation run.
     *
     * @param string $uuid
     * @param string $message
     * @return void
     */
    public function markGenerationFailed(string $uuid, string $message): void
    {
        RosterGeneration::query()
            ->where('uuid', $uuid)
            ->update([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => Carbon::now(),
            ]);
    }

    /**
     * Persist a completed generation's stored preview as a draft roster.
     *
     * @param RosterGeneration $generation
     * @param int $userId
     * @param bool $publish
     * @return Roster
     *
     * @throws RosterStatusException
     */
    public function saveGenerationAsDraft(RosterGeneration $generation, int $userId, bool $publish): Roster
    {
        $roster = $this->persister->saveFromGeneration($generation, $userId);

        $generation->update(['roster_id' => $roster->getKey()]);

        if ($publish) {
            $roster = $this->persister->publish($roster);
        }

        return $roster;
    }

    /**
     * Regenerate and persist a draft roster for the requested period.
     *
     * @param int $year
     * @param int $month
     * @param int $createdBy
     * @return Roster
     * @throws Exception
     */
    public function saveDraft(int $year, int $month, int $createdBy): Roster
    {
        $result = $this->generator->generate($year, $month);

        return $this->persister->save($result, $createdBy);
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
     * Build the enriched, storage-ready preview payload from a generation result.
     *
     * Returns plain arrays only (no API resources) so the payload can be stored
     * verbatim as JSON and replayed to the client without re-enrichment.
     *
     * @param GenerationResult $result
     * @return array{
     *     year: int,
     *     month: int,
     *     assignments: list<array<string, mixed>>,
     *     reports: array{coverage_shortages: list<array<string, mixed>>, hours_shortfalls: list<array<string, mixed>>},
     *     summary: array<string, mixed>
     * }
     */
    private function buildPreviewPayload(GenerationResult $result): array
    {
        $lookups = $this->warmPreviewLookups($result);

        $assignments = array_map(
            fn (array $assignment): array => $this->enrichPreviewAssignment($assignment, $lookups),
            $result->assignments,
        );

        return [
            'year' => $result->year,
            'month' => $result->month,
            'assignments' => $assignments,
            'reports' => $this->reportService->reports($result->coverageShortages, $result->hoursShortfalls),
            'summary' => $this->reportService->summary(
                count($result->assignments),
                $result->coverageShortages,
                $result->hoursShortfalls,
            ),
        ];
    }

    /**
     * Enrich an assignment with the worker, shift, and role data.
     *
     * The shape mirrors RosterAssignmentResource so previews and saved rosters
     * present an identical assignment payload to the client.
     *
     * @param  array{worker_id: int, shift_id: int, work_date: CarbonImmutable, source: string}  $assignment
     * @param  array{
     *     workers: Collection<int, Worker>,
     *     shifts: Collection<int, Shift>,
     *     roles: Collection<int, Role>
     * }  $lookups
     * @return array<string, mixed>
     */
    private function enrichPreviewAssignment(array $assignment, array $lookups): array
    {
        $worker = $lookups['workers']->get($assignment['worker_id']);
        $shift = $lookups['shifts']->get($assignment['shift_id']);
        $role = $worker !== null ? $lookups['roles']->get((int) $worker->role_id) : null;

        return [
            'id' => null,
            'worker_id' => $assignment['worker_id'],
            'worker_name' => $worker?->full_name,
            'shift_id' => $assignment['shift_id'],
            'shift_code' => $shift?->code,
            'role_id' => $role?->id,
            'role_name' => $role?->name,
            'work_date' => $assignment['work_date']->toDateString(),
            'source' => $assignment['source'],
        ];
    }

    /**
     * Warm the lookups for the generation result.
     *
     * @param GenerationResult $result
     * @return array{
     *     workers: Collection<int, Worker>,
     *     shifts: Collection<int, Shift>,
     *     roles: Collection<int, Role>
     * }
     */
    private function warmPreviewLookups(GenerationResult $result): array
    {
        $workerIds = array_unique(array_merge(
            array_column($result->assignments, 'worker_id'),
            array_column($result->hoursShortfalls, 'worker_id'),
        ));

        $shiftIds = array_unique(array_merge(
            array_column($result->assignments, 'shift_id'),
            array_column($result->coverageShortages, 'shift_id'),
        ));

        $roleIds = array_unique(array_column($result->coverageShortages, 'role_id'));

        $workers = Worker::query()
            ->whereIn('id', $workerIds)
            ->get()
            ->keyBy('id');

        foreach ($workers as $worker) {
            $roleIds[] = (int) $worker->role_id;
        }

        return [
            'workers' => $workers,
            'shifts' => Shift::query()->whereIn('id', $shiftIds)->get()->keyBy('id'),
            'roles' => Role::query()->whereIn('id', array_unique($roleIds))->get()->keyBy('id'),
        ];
    }
}
