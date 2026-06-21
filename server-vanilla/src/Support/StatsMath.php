<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Shared per-worker statistics math (the roster stats leaderboards). Mirrors
 * usort is stable on PHP 8, so
 * ties keep their incoming order (israeli_id).
 */
final class StatsMath
{
    private const int LEADERBOARD_SIZE = 5;

    /**
     * Build the standard leaderboards from per-worker stat rows.
     *
     * @param  list<WorkerStatsRow>  $rows
     * @return array{
     *     highest_paid: list<array<string, mixed>>,
     *     lowest_paid: list<array<string, mixed>>,
     *     most_hours: list<array<string, mixed>>,
     *     fewest_hours: list<array<string, mixed>>
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
     * Top-N rows by a numeric property, reduced to slim leaderboard entries.
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
