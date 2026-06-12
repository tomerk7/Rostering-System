<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Enums\RosterStatus;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Services\Rostering\Data\GenerationResult;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Persists generated rosters and their assignments atomically within a
 * database transaction.
 */
final readonly class RosterPersister
{
    /**
     * Save a generated roster with its assignments.
     */
    public function save(GenerationResult $result, int $createdBy): Roster
    {
        return DB::transaction(function () use ($result, $createdBy): Roster {
            $now = Carbon::now();

            $roster = Roster::query()->create([
                'year' => $result->year,
                'month' => $result->month,
                'status' => RosterStatus::Published,
                'generated_at' => $now,
                'published_at' => $now,
                'created_by' => $createdBy,
            ]);

            $this->insertAssignments($roster, $result->assignments);

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
}
