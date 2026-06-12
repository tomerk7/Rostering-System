<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

/**
 * Per-worker stat rows and roster-level summary for a saved roster.
 */
final readonly class RosterStatsResult
{
    /**
     * @param  list<WorkerStatsRow>  $rows
     * @param  array{
     *     total_cost: float,
     *     total_hours: int,
     *     workers_with_shortfall: int,
     *     leaderboards: array<string, list<array<string, mixed>>>
     * }  $summary
     */
    public function __construct(
        public array $rows,
        public array $summary,
    ) {}

    /**
     * @return array{
     *     rows: list<array{
     *         worker_id: string,
     *         name: string,
     *         role: string,
     *         min_hours: int,
     *         max_hours: int,
     *         actual_hours: int,
     *         percent_of_min: float,
     *         percent_of_max: float,
     *         total_cost: float,
     *         shortfall_hours: int
     *     }>,
     *     summary: array{
     *         total_cost: float,
     *         total_hours: int,
     *         workers_with_shortfall: int,
     *         leaderboards: array<string, list<array<string, mixed>>>
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'rows' => array_map(static fn (WorkerStatsRow $row): array => $row->toArray(), $this->rows),
            'summary' => $this->summary,
        ];
    }
}
