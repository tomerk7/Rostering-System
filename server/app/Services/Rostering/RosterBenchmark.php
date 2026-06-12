<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Exceptions\Rostering\BenchmarkException;
use App\Services\Rostering\Data\BenchmarkResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Compare a plain greedy roster against the cost-optimized one for a month.
 *
 * A tuning aid for OptimizerConfig (lambda, temperature, cooling, iterations):
 * both rosters are generated as previews only and nothing is persisted.
 */
final readonly class RosterBenchmark
{
    private const int SHIFT_HOURS = 8;

    public function __construct(private RosterGenerator $generator) {}

    /**
     * Run a plain vs cost-optimized generation benchmark for the given month
     * in the current year. Both runs are previews only — nothing is saved.
     *
     * @param  int  $year
     * @param  int  $month
     * @return BenchmarkResult
     * @throws BenchmarkException when no contracts exist
     */
    public function run(int $year, int $month): BenchmarkResult
    {
        $costs = DB::table('contracts')->pluck('hourly_cost', 'worker_id');
        $minHours = DB::table('contracts')->pluck('min_monthly_hours', 'worker_id');
        $maxHours = DB::table('contracts')->pluck('max_monthly_hours', 'worker_id');

        if ($costs->isEmpty()) {
            throw BenchmarkException::noContracts();
        }

        $startedAt = microtime(true);
        $plain = $this->generator->generate($year, $month);
        $plainSeconds = microtime(true) - $startedAt;

        $startedAt = microtime(true);
        $optimized = $this->generator->generate($year, $month, optimizeCost: true);
        $optimizedSeconds = microtime(true) - $startedAt;

        $costPlain = $this->totalCost($plain->assignments, $costs);
        $costOptimized = $this->totalCost($optimized->assignments, $costs);
        $saved = $costPlain - $costOptimized;

        return new BenchmarkResult(
            year: $year,
            month: $month,
            plain: $this->metrics($plain->assignments, count($plain->coverageShortages), count($plain->hoursShortfalls), $costPlain, $plainSeconds, $minHours, $maxHours),
            optimized: $this->metrics($optimized->assignments, count($optimized->coverageShortages), count($optimized->hoursShortfalls), $costOptimized, $optimizedSeconds, $minHours, $maxHours),
            savedAmount: $saved,
            savedPercent: $costPlain > 0 ? ($saved / $costPlain) * 100 : 0.0,
            assignmentsMatch: count($plain->assignments) === count($optimized->assignments),
        );
    }

    /**
     * Calculate the metrics for a given roster.
     * @param  list<array{worker_id: string}>  $assignments
     * @param  int  $coverageShortages
     * @param  int  $hoursShortfallWorkers
     * @param  float  $totalCost
     * @param  float  $seconds
     * @param  Collection<array-key, mixed>  $minHours
     * @param  Collection<array-key, mixed>  $maxHours
     * @return array<string, int|float>
     */
    private function metrics(array $assignments, int $coverageShortages, int $hoursShortfallWorkers, float $totalCost, float $seconds, Collection $minHours, Collection $maxHours): array {
        $scheduled = $this->scheduledHours($assignments);

        return [
            'assignments' => count($assignments),
            'coverage_shortages' => $coverageShortages,
            'total_cost' => $totalCost,
            'min_hours_shortfall_workers' => $hoursShortfallWorkers,
            'min_hours_shortfall_hours' => $this->shortfallHours($scheduled, $minHours),
            'max_hours_violations' => $this->maxViolations($scheduled, $maxHours),
            'hours_std_dev' => $this->hoursStdDev($scheduled),
            'generation_seconds' => $seconds,
        ];
    }

    /**
     * This function calculates the total cost of the roster.
     * 
     * @param  list<array{worker_id: string}>  $assignments
     * @param  Collection<array-key, mixed>  $costs
     */
    private function totalCost(array $assignments, Collection $costs): float
    {
        $total = 0.0;

        foreach ($assignments as $assignment) {
            $total += (float) $costs[$assignment['worker_id']] * self::SHIFT_HOURS;
        }

        return $total;
    }

    /**
     * This function calculates the total cost of the roster.
     * 
     * @param  list<array{worker_id: string}>  $assignments
     * @return array<string, int>
     */
    private function scheduledHours(array $assignments): array
    {
        $scheduled = [];

        foreach ($assignments as $assignment) {
            $scheduled[$assignment['worker_id']] = ($scheduled[$assignment['worker_id']] ?? 0) + self::SHIFT_HOURS;
        }

        return $scheduled;
    }

    /**
     * This function calculates the total shortfall hours of the roster.
     * 
     * @param  array<string, int>  $scheduled
     * @param  Collection<array-key, mixed>  $minHours
     */
    private function shortfallHours(array $scheduled, Collection $minHours): int
    {
        $total = 0;

        foreach ($minHours as $workerId => $min) {
            $total += max(0, (int) $min - ($scheduled[$workerId] ?? 0));
        }

        return $total;
    }

    /**
     * This function calculates the total max violations of the roster.
     * 
     * @param  array<string, int>  $scheduled
     * @param  Collection<array-key, mixed>  $maxHours
     */
    private function maxViolations(array $scheduled, Collection $maxHours): int
    {
        $count = 0;

        foreach ($maxHours as $workerId => $max) {
            if (($scheduled[$workerId] ?? 0) > (int) $max) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * This function calculates the total standard deviation of the roster.
     * 
     * @param  array<string, int>  $scheduled
     * @return float
     */
    private function hoursStdDev(array $scheduled): float
    {
        $hours = array_values($scheduled);

        if (count($hours) < 2) {
            return 0.0;
        }

        $mean = array_sum($hours) / count($hours);
        $variance = array_sum(array_map(fn ($h) => ($h - $mean) ** 2, $hours)) / count($hours);

        return sqrt($variance);
    }
}
