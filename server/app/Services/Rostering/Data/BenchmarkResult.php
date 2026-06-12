<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

/**
 * The outcome of benchmarking plain greedy generation against the
 * cost-optimized run for one month. Nothing is persisted — both runs are
 * previews, and this object only carries the comparison metrics.
 *
 * Each per-run metrics array has the shape:
 *
 *   array{assignments: int, coverage_shortages: int, total_cost: float,
 *         min_hours_shortfall_workers: int, min_hours_shortfall_hours: int,
 *         max_hours_violations: int, hours_std_dev: float, generation_seconds: float}
 */
final readonly class BenchmarkResult
{
    /**
     * @param  array<string, int|float>  $plain
     * @param  array<string, int|float>  $optimized
     */
    public function __construct(
        public int $year,
        public int $month,
        public array $plain,
        public array $optimized,
        public float $savedAmount,
        public float $savedPercent,
        public bool $assignmentsMatch,
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
        ];
    }
}
