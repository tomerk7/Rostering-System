<?php

declare(strict_types=1);

namespace App\Services\Rostering\Support;

use App\Services\Rostering\Data\WorkerStatsRow;

/**
 * Shared per-worker statistics math so the roster stats service, the CSV
 * exporter, and the benchmark stay numerically identical.
 */
final class StatsMath
{
    private const int LEADERBOARD_SIZE = 5;

    /**
     * Utilization percentage with division-by-zero protection.
     * 
     * @param int $actualHours
     * @param int $targetHours
     * @param bool $capAtHundred
     * @return float
     */
    public static function percentOf(int $actualHours, int $targetHours, bool $capAtHundred): float
    {
        if ($targetHours <= 0) {
            return 0.0;
        }

        $percent = ($actualHours / $targetHours) * 100;

        if ($capAtHundred) {
            $percent = min($percent, 100);
        }

        return round($percent, 2);
    }

    /**
     * Build the standard leaderboards from per-worker stat rows.
     *
     * @param  list<WorkerStatsRow>  $rows
     * @return array{
     *     highest_paid: list<array{worker_id: string, name: string, total_cost: float}>,
     *     lowest_paid: list<array{worker_id: string, name: string, total_cost: float}>,
     *     most_hours: list<array{worker_id: string, name: string, actual_hours: int}>,
     *     fewest_hours: list<array{worker_id: string, name: string, actual_hours: int}>
     * }
     */
    public static function leaderboards(array $rows): array
    {
        return [
            'highest_paid' => self::topBy($rows, 'totalCost', 'total_cost', descending: true),
            'lowest_paid' => self::topBy($rows, 'totalCost', 'total_cost', descending: false),
            'most_hours' => self::topBy($rows, 'actualHours', 'actual_hours', descending: true),
            'fewest_hours' => self::topBy($rows, 'actualHours', 'actual_hours', descending: false),
        ];
    }

    /**
     * Top-N rows by a numeric field, reduced to slim leaderboard entries.
     *
     * @param  list<WorkerStatsRow>  $rows
     * @return list<array<string, mixed>>
     */
    private static function topBy(array $rows, string $property, string $outputKey, bool $descending): array
    {
        usort($rows, static function (WorkerStatsRow $left, WorkerStatsRow $right) use ($property, $descending): int {
            $leftValue = $left->{$property};
            $rightValue = $right->{$property};

            return $descending ? $rightValue <=> $leftValue : $leftValue <=> $rightValue;
        });

        return array_map(
            static fn (WorkerStatsRow $row): array => [
                'worker_id' => $row->workerId,
                'name' => $row->name,
                $outputKey => $row->{$property},
            ],
            array_slice($rows, 0, self::LEADERBOARD_SIZE),
        );
    }
}
