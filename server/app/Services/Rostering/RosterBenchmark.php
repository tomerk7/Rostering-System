<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Exceptions\Rostering\BenchmarkException;
use App\Services\Rostering\Data\BenchmarkResult;
use App\Services\Rostering\Data\GenerationResult;
use App\Services\Rostering\Data\WorkerStatsRow;
use App\Services\Rostering\Support\StatsMath;
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

    /**
     * Full per-worker tables are omitted from the payload above this many
     * workers; deltas and leaderboards are always included.
     */
    private const int WORKER_DETAIL_LIMIT = 300;

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

        $names = DB::table('workers')->whereNull('deleted_at')->pluck('full_name', 'israeli_id');
        $plainRows = $this->workerRows($plain, $costs, $minHours, $maxHours, $names);
        $optimizedRows = $this->workerRows($optimized, $costs, $minHours, $maxHours, $names);

        return new BenchmarkResult(
            year: $year,
            month: $month,
            plain: $this->metrics($plain->assignments, count($plain->coverageShortages), count($plain->hoursShortfalls), $costPlain, $plainSeconds, $minHours, $maxHours),
            optimized: $this->metrics($optimized->assignments, count($optimized->coverageShortages), count($optimized->hoursShortfalls), $costOptimized, $optimizedSeconds, $minHours, $maxHours),
            savedAmount: $saved,
            savedPercent: $costPlain > 0 ? ($saved / $costPlain) * 100 : 0.0,
            assignmentsMatch: count($plain->assignments) === count($optimized->assignments),
            workerStats: $this->workerStats($plainRows, $optimizedRows),
        );
    }

    /**
     * Assemble the per-worker payload: full tables (size-limited), deltas of
     * workers whose stats changed between runs, and per-variant leaderboards.
     *
     * @param  list<WorkerStatsRow>  $plainRows
     * @param  list<WorkerStatsRow>  $optimizedRows
     * @return array{
     *     plain: list<array<string, mixed>>,
     *     optimized: list<array<string, mixed>>,
     *     deltas: list<array<string, mixed>>,
     *     leaderboards: array{plain: array<string, mixed>, optimized: array<string, mixed>},
     *     truncated: bool
     * }
     */
    private function workerStats(array $plainRows, array $optimizedRows): array
    {
        $workerCount = count(array_unique([
            ...array_map(static fn (WorkerStatsRow $row): string => $row->workerId, $plainRows),
            ...array_map(static fn (WorkerStatsRow $row): string => $row->workerId, $optimizedRows),
        ]));
        $includeRows = $workerCount <= self::WORKER_DETAIL_LIMIT;

        return [
            'plain' => $includeRows
                ? array_map(static fn (WorkerStatsRow $row): array => $row->toArray(), $plainRows)
                : [],
            'optimized' => $includeRows
                ? array_map(static fn (WorkerStatsRow $row): array => $row->toArray(), $optimizedRows)
                : [],
            'deltas' => $this->workerDeltas($plainRows, $optimizedRows),
            'leaderboards' => [
                'plain' => StatsMath::leaderboards($plainRows),
                'optimized' => StatsMath::leaderboards($optimizedRows),
            ],
            'truncated' => ! $includeRows,
        ];
    }

    /**
     * Build per-worker stat rows for one generated preview, using the same
     * field names as RosterStatsService so the frontend grid is reusable.
     *
     * Includes workers with a min-hours shortfall even when they received no
     * assignments at all.
     *
     * @param  GenerationResult  $result
     * @param  Collection<array-key, mixed>  $costs
     * @param  Collection<array-key, mixed>  $minHours
     * @param  Collection<array-key, mixed>  $maxHours
     * @param  Collection<array-key, mixed>  $names
     * @return list<WorkerStatsRow>
     */
    private function workerRows(GenerationResult $result, Collection $costs, Collection $minHours, Collection $maxHours, Collection $names): array
    {
        $scheduled = $this->scheduledHours($result->assignments);

        $shortfalls = [];

        foreach ($result->hoursShortfalls as $shortfall) {
            $shortfalls[$shortfall['worker_id']] = $shortfall['min_hours'] - $shortfall['scheduled_hours'];
        }

        $workerIds = array_unique([...array_keys($scheduled), ...array_keys($shortfalls)]);
        $rows = [];

        foreach ($workerIds as $workerId) {
            $hours = $scheduled[$workerId] ?? 0;
            $min = (int) ($minHours[$workerId] ?? 0);
            $max = (int) ($maxHours[$workerId] ?? 0);

            $rows[] = WorkerStatsRow::fromHoursAndCost(
                workerId: (string) $workerId,
                name: (string) ($names[$workerId] ?? $workerId),
                minHours: $min,
                maxHours: $max,
                actualHours: $hours,
                totalCost: $hours * (float) ($costs[$workerId] ?? 0),
                shortfallHours: max(0, $shortfalls[$workerId] ?? 0),
            );
        }

        return $rows;
    }

    /**
     * Per-worker plain-vs-optimized deltas, limited to workers whose hours,
     * cost, or shortfall status changed between the two runs.
     *
     * @param  list<WorkerStatsRow>  $plainRows
     * @param  list<WorkerStatsRow>  $optimizedRows
     * @return list<array{
     *     worker_id: string,
     *     name: string,
     *     plain_hours: int,
     *     optimized_hours: int,
     *     hours_delta: int,
     *     plain_cost: float,
     *     optimized_cost: float,
     *     cost_delta: float,
     *     shortfall_change: 'appeared'|'disappeared'|null
     * }>
     */
    private function workerDeltas(array $plainRows, array $optimizedRows): array
    {
        $plainByWorker = [];

        foreach ($plainRows as $row) {
            $plainByWorker[$row->workerId] = $row;
        }

        $optimizedByWorker = [];

        foreach ($optimizedRows as $row) {
            $optimizedByWorker[$row->workerId] = $row;
        }

        $workerIds = array_unique([...array_keys($plainByWorker), ...array_keys($optimizedByWorker)]);

        $deltas = [];

        foreach ($workerIds as $workerId) {
            $plainRow = $plainByWorker[$workerId] ?? null;
            $optimizedRow = $optimizedByWorker[$workerId] ?? null;

            $plainHours = $plainRow?->actualHours ?? 0;
            $optimizedHours = $optimizedRow?->actualHours ?? 0;
            $plainCost = $plainRow?->totalCost ?? 0.0;
            $optimizedCost = $optimizedRow?->totalCost ?? 0.0;

            $hadShortfall = ($plainRow?->shortfallHours ?? 0) > 0;
            $hasShortfall = ($optimizedRow?->shortfallHours ?? 0) > 0;
            $shortfallChange = match (true) {
                ! $hadShortfall && $hasShortfall => 'appeared',
                $hadShortfall && ! $hasShortfall => 'disappeared',
                default => null,
            };

            if ($plainHours === $optimizedHours && $plainCost === $optimizedCost && $shortfallChange === null) {
                continue;
            }

            $deltas[] = [
                'worker_id' => (string) $workerId,
                'name' => (string) (($plainRow ?? $optimizedRow)?->name ?? $workerId),
                'plain_hours' => $plainHours,
                'optimized_hours' => $optimizedHours,
                'hours_delta' => $optimizedHours - $plainHours,
                'plain_cost' => $plainCost,
                'optimized_cost' => $optimizedCost,
                'cost_delta' => round($optimizedCost - $plainCost, 2),
                'shortfall_change' => $shortfallChange,
            ];
        }

        usort(
            $deltas,
            static fn (array $left, array $right): int => abs($right['cost_delta']) <=> abs($left['cost_delta']),
        );

        return $deltas;
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
