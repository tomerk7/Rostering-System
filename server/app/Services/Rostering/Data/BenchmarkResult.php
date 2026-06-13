<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

/**
 * The outcome of benchmarking plain greedy generation against the
 * cost-optimized run for one month. Nothing is persisted — both runs are
 * previews, and this object only carries the comparison metrics.
 *
 */
final readonly class BenchmarkResult
{
    /**
     * @param  array<string, int|float>  $plain
     * @param  array<string, int|float>  $optimized
     * @param  array{
     *     plain: list<array<string, mixed>>,
     *     optimized: list<array<string, mixed>>,
     *     deltas: list<array<string, mixed>>,
     *     leaderboards: array{plain: array<string, mixed>, optimized: array<string, mixed>},
     *     truncated: bool
     * }  $workerStats
     */
    public function __construct(
        public int $year,
        public int $month,
        public array $plain,
        public array $optimized,
        public float $savedAmount,
        public float $savedPercent,
        public bool $assignmentsMatch,
        public array $workerStats,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'plain' => $this->plain,
            'optimized' => $this->optimized,
            'saved_amount' => $this->savedAmount,
            'saved_percent' => $this->savedPercent,
            'assignments_match' => $this->assignmentsMatch,
            'worker_stats' => $this->workerStats,
        ];
    }
}
