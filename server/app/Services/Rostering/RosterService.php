<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Models\Roster;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

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
     * Load one roster with enriched assignments, optionally filtered by date or shift.
     * 
     * @param Roster $roster
     * @param Request $request
     * @return Roster
     */
    public function loadDetails(Roster $roster, Request $request): Roster
    {
        $assignmentsQuery = $roster->assignments()
            ->with(['worker.role', 'shift'])
            ->orderBy('work_date')
            ->orderBy('shift_id')
            ->orderBy('worker_id');

        if ($request->filled('date')) {
            $assignmentsQuery->whereDate('work_date', (string) $request->query('date'));
        }

        if ($request->filled('shift_id')) {
            $assignmentsQuery->where('shift_id', (int) $request->query('shift_id'));
        }

        $roster->setRelation('assignments', $assignmentsQuery->get());

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
}
