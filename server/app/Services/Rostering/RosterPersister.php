<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\RosterStatus;
use App\Exceptions\Rostering\RosterStatusException;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\RosterGeneration;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists generated rosters and their assignments.
 *
 * Handles draft creation and roster publication while enforcing
 * roster status rules and ensuring all changes are committed
 * atomically within a database transaction.
 */
final readonly class RosterPersister
{
    /**
     * Save a generated preview as a new draft roster with its assignments.
     */
    public function save(GenerationResult $result, int $createdBy): Roster
    {
        return DB::transaction(function () use ($result, $createdBy): Roster {
            $roster = Roster::query()->create([
                'year' => $result->year,
                'month' => $result->month,
                'status' => RosterStatus::Draft,
                'generated_at' => Carbon::now(),
                'created_by' => $createdBy,
            ]);

            $this->insertAssignments($roster, $result->assignments);

            return $roster;
        });
    }

    /**
     * Save a completed generation's stored preview as a new draft roster.
     *
     * Persists the assignments verbatim from the generation's JSON payload so the
     * saved draft matches the previewed result exactly, without re-running the
     * engine.
     *
     * @param RosterGeneration $generation
     * @param int $createdBy
     * @return Roster
     */
    public function saveFromGeneration(RosterGeneration $generation, int $createdBy): Roster
    {
        return DB::transaction(function () use ($generation, $createdBy): Roster {
            $roster = Roster::query()->create([
                'year' => $generation->year,
                'month' => $generation->month,
                'status' => RosterStatus::Draft,
                'generated_at' => Carbon::now(),
                'created_by' => $createdBy,
            ]);

            $this->insertStoredAssignments($roster, $generation->assignments ?? []);

            return $roster;
        });
    }

    /**
     * Publish a draft roster, superseding any roster already published for the
     * same month.
     *
     * @throws RosterStatusException when the roster is not a draft
     */
    public function publish(Roster $roster): Roster
    {
        if ($roster->status !== RosterStatus::Draft) {
            throw RosterStatusException::cannotPublishNonDraft($roster->status);
        }

        return DB::transaction(function () use ($roster): Roster {
            Roster::query()
                ->forPeriod($roster->year, $roster->month)
                ->published()
                ->whereKeyNot($roster->getKey())
                ->update(['status' => RosterStatus::Superseded]);

            $roster->update([
                'status' => RosterStatus::Published,
                'published_at' => Carbon::now(),
            ]);

            return $roster;
        });
    }

    /**
     * Delete a roster and its assignments.
     */
    public function delete(Roster $roster): void
    {
        $roster->delete();
    }

    /**
     * Bulk-insert the generation assignments for a roster.
     *
     * Uses a single insert for the high-volume fact table; timestamps and the
     * date string are set explicitly because insert() bypasses Eloquent casts.
     *
     * @param  list<array{worker_id: int, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
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
     * Bulk-insert assignments from a generation's stored JSON payload.
     *
     * Reads only the persistence-relevant keys from each enriched assignment;
     * work_date is already a date string in the stored payload.
     *
     * @param  list<array{worker_id: int, shift_id: int, work_date: string, source: string}>  $assignments
     */
    private function insertStoredAssignments(Roster $roster, array $assignments): void
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
                'work_date' => $assignment['work_date'],
                'source' => $assignment['source'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $assignments,
        );

        RosterAssignment::query()->insert($rows);
    }
}
