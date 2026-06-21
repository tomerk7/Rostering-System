<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Exceptions\BenchmarkException;
use App\Repositories\ContractRepository;
use App\Repositories\WorkerRepository;
use App\Services\Rostering\Data\BenchmarkResult;
use App\Services\Rostering\Data\DistributionPreference;
use App\Services\Rostering\Data\GenerationResult;
use App\Support\StatsMath;
use App\Support\WorkerStatsRow;
use Exception;

/**
 * Compare a plain greedy roster against the cost-optimized one for a month.
 *
 * A tuning aid for OptimizerConfig (shortfallPenaltyPerHour, balanceWeight,
 * temperature, cooling, iterations): both rosters are generated as previews only
 * and nothing is persisted. Benchmark preview only;
 * the data loading (Eloquent/query builder → repositories) and the Collection
 * plucks (→ plain arrays) differ.
 */
class RosterBenchmark
{
    private const int SHIFT_HOURS = 8;

    /**
     * Class constructor.
     *
     * @param RosterGenerator $generator
     * @param ContractRepository $contracts
     * @param WorkerRepository $workers
     * @param OptimizerPenaltyAdvisor $penaltyAdvisor
     */
    public function __construct(
        private RosterGenerator $generator = new RosterGenerator,
        private ContractRepository $contracts = new ContractRepository,
        private WorkerRepository $workers = new WorkerRepository,
        private OptimizerPenaltyAdvisor $penaltyAdvisor = new OptimizerPenaltyAdvisor,
    ) {}

    /**
     * Benchmark two generation runs for the given month and report how they
     * differ. The baseline ("plain") is greedy generation by default, or an
     * optimized run when a baseline balance weight is given.
     *
     * @param int $year
     * @param int $month
     * @param DistributionPreference|null $preference
     * @param float|null $baselineBalanceWeight
     * @return BenchmarkResult
     *
     * @throws BenchmarkException|Exception
     */
    public function run(int $year, int $month, ?DistributionPreference $preference = null, ?float $baselineBalanceWeight = null): BenchmarkResult
    {
        ['costs' => $costs, 'minHours' => $minHours, 'maxHours' => $maxHours] = $this->contracts->inputsByWorker();

        if ($costs === []) {
            throw BenchmarkException::noContracts();
        }

        // Resolve the optimized run's penalties from live data, exactly as the
        // worker job does, so the preview reflects real generation.
        $balanceWeight = null;
        $shortfallPenaltyPerHour = null;

        if ($preference !== null) {
            $penalties = $this->penaltyAdvisor->penaltiesFor($preference);
            $balanceWeight = $penalties['balanceWeight'];
            $shortfallPenaltyPerHour = $penalties['shortfallPenaltyPerHour'];
        }

        $startedAt = microtime(true);
        $plain = $baselineBalanceWeight === null
            ? $this->generator->generate($year, $month)
            : $this->generator->generate($year, $month, optimizeCost: true, balanceWeight: $baselineBalanceWeight);
        $plainSeconds = microtime(true) - $startedAt;

        $startedAt = microtime(true);
        $optimized = $this->generator->generate(
            $year,
            $month,
            optimizeCost: true,
            balanceWeight: $balanceWeight,
            shortfallPenaltyPerHour: $shortfallPenaltyPerHour,
        );
        $optimizedSeconds = microtime(true) - $startedAt;

        $costPlain = $this->totalCost($plain->assignments, $costs);
        $costOptimized = $this->totalCost($optimized->assignments, $costs);
        $saved = $costPlain - $costOptimized;

        $names = $this->workers->namesByIdExcludingTrashed();
        $plainRows = $this->workerRows($plain, $costs, $minHours, $maxHours, $names);
        $optimizedRows = $this->workerRows($optimized, $costs, $minHours, $maxHours, $names);

        return new BenchmarkResult(
            year: $year,
            month: $month,
            plain: $this->metrics($plain->assignments, count($plain->hoursShortfalls), $costPlain, $plainSeconds, $minHours),
            optimized: $this->metrics($optimized->assignments, count($optimized->hoursShortfalls), $costOptimized, $optimizedSeconds, $minHours),
            savedAmount: $saved,
            savedPercent: $costPlain > 0 ? ($saved / $costPlain) * 100 : 0.0,
            assignmentsMatch: count($plain->assignments) === count($optimized->assignments),
            workerStats: $this->workerStats($plainRows, $optimizedRows),
        );
    }

    /**
     * Assemble the per-worker payload: deltas of workers whose stats changed
     * between runs, and per-variant leaderboards.
     *
     * @param  list<WorkerStatsRow>  $plainRows
     * @param  list<WorkerStatsRow>  $optimizedRows
     * @return array{
     *     deltas: list<array<string, mixed>>,
     *     leaderboards: array{plain: array<string, mixed>, optimized: array<string, mixed>}
     * }
     */
    private function workerStats(array $plainRows, array $optimizedRows): array
    {
        return [
            'deltas' => $this->workerDeltas($plainRows, $optimizedRows),
            'leaderboards' => [
                'plain' => StatsMath::leaderboards($plainRows),
                'optimized' => StatsMath::leaderboards($optimizedRows),
            ],
        ];
    }

    /**
     * Build per-worker stat rows for one generated preview, using the same field
     * names as RosterReportService::forRoster so the frontend grid is reusable. Includes
     * workers with a min-hours shortfall even when they received no assignments.
     *
     * @param GenerationResult $result
     * @param  array<string, string>  $costs
     * @param  array<string, int>  $minHours
     * @param  array<string, int>  $maxHours
     * @param  array<string, string>  $names
     * @return list<WorkerStatsRow>
     */
    private function workerRows(GenerationResult $result, array $costs, array $minHours, array $maxHours, array $names): array
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
     * Per-worker plain-vs-optimized deltas, limited to workers whose hours, cost,
     * or shortfall status changed between the two runs.
     *
     * @param  list<WorkerStatsRow>  $plainRows
     * @param  list<WorkerStatsRow>  $optimizedRows
     * @return list<array<string, mixed>>
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

        usort($deltas, static function (array $left, array $right): int {
            $leftCost = abs($left['cost_delta']);
            $rightCost = abs($right['cost_delta']);

            if ($rightCost > $leftCost) {
                return 1;
            }

            if ($rightCost < $leftCost) {
                return -1;
            }

            return 0;
        });

        return $deltas;
    }

    /**
     * Calculate the metrics for a given roster.
     *
     * @param  list<array{worker_id: string}>  $assignments
     * @param int $hoursShortfallWorkers
     * @param float $totalCost
     * @param float $seconds
     * @param  array<string, int>  $minHours
     * @return array<string, int|float>
     */
    private function metrics(array $assignments, int $hoursShortfallWorkers, float $totalCost, float $seconds, array $minHours): array
    {
        $scheduled = $this->scheduledHours($assignments);

        return [
            'assignments' => count($assignments),
            'total_cost' => $totalCost,
            'min_hours_shortfall_workers' => $hoursShortfallWorkers,
            'min_hours_shortfall_hours' => $this->shortfallHours($scheduled, $minHours),
            'hours_std_dev' => $this->hoursStdDev($scheduled),
            'generation_seconds' => $seconds,
        ];
    }

    /**
     * The total cost of a roster (8h per assignment × the worker's rate).
     *
     * @param  list<array{worker_id: string}>  $assignments
     * @param  array<string, string>  $costs
     * @return float
     */
    private function totalCost(array $assignments, array $costs): float
    {
        $total = 0.0;

        foreach ($assignments as $assignment) {
            $total += (float) $costs[$assignment['worker_id']] * self::SHIFT_HOURS;
        }

        return $total;
    }

    /**
     * Scheduled hours per worker (8h per assignment).
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
     * Total shortfall hours below each worker's minimum.
     *
     * @param  array<string, int>  $scheduled
     * @param  array<string, int>  $minHours
     * @return int
     */
    private function shortfallHours(array $scheduled, array $minHours): int
    {
        $total = 0;

        foreach ($minHours as $workerId => $min) {
            $total += max(0, (int) $min - ($scheduled[$workerId] ?? 0));
        }

        return $total;
    }

    /**
     * Population standard deviation of scheduled hours.
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
