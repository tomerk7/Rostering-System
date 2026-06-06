<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Exceptions\Rostering\RosterStatusException;
use App\Models\Roster;
use Illuminate\Database\Eloquent\Collection;

/**
 * Loads and persists saved rosters for the HTTP API.
 */
final readonly class RosterService
{
    /**
     * Constructor.
     *
     * @param RosterGenerator $generator
     * @param RosterPersister $persister
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
     * Regenerate and persist a draft roster for the requested period.
     * 
     * @param int $year
     * @param int $month
     * @param int $createdBy
     * @return Roster
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
     */
    public function delete(Roster $roster): void
    {
        $this->persister->delete($roster);
    }
}
