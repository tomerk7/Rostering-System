<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\Rostering\BenchmarkException;
use App\Services\Rostering\RosterBenchmark;
use Illuminate\Console\Command;

/**
 * Compare a plain greedy roster against the cost-optimized one for a month.
 *
 * A development aid for tuning OptimizerConfig (lambda, temperature, cooling,
 * iterations): tweak the config defaults, rerun, and compare the numbers.
 * Nothing is persisted — both runs are previews only.
 */
final class BenchmarkRosterOptimization extends Command
{
    protected $signature = 'roster:benchmark {year=2026} {month=7}';

    protected $description = 'Compare greedy vs cost-optimized roster generation for a month (nothing is saved)';

    public function handle(RosterBenchmark $benchmark): int
    {
        $year = (int) $this->argument('year');
        $month = (int) $this->argument('month');

        $this->info("Generating {$year}-{$month} twice (plain, then optimized)...");

        try {
            $result = $benchmark->run($year, $month);
        } catch (BenchmarkException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $plain = $result->plain;
        $optimized = $result->optimized;

        $this->table(
            ['Metric', 'Plain (greedy)', 'Optimized (greedy + SA)'],
            [
                ['Assignments', $plain['assignments'], $optimized['assignments']],
                ['Coverage shortages', $plain['coverage_shortages'], $optimized['coverage_shortages']],
                ['Total cost', number_format($plain['total_cost'], 2), number_format($optimized['total_cost'], 2)],
                ['Min-hours shortfall (workers)', $plain['min_hours_shortfall_workers'], $optimized['min_hours_shortfall_workers']],
                ['Min-hours shortfall (hours)', $plain['min_hours_shortfall_hours'], $optimized['min_hours_shortfall_hours']],
                ['Max-hours violations (workers)', $plain['max_hours_violations'], $optimized['max_hours_violations']],
                ['Hours std deviation', sprintf('%.2f', $plain['hours_std_dev']), sprintf('%.2f', $optimized['hours_std_dev'])],
                ['Generation time', sprintf('%.2fs', $plain['generation_seconds']), sprintf('%.2fs', $optimized['generation_seconds'])],
            ],
        );

        $this->info(sprintf(
            'Saved: %s (%.2f%%)',
            number_format($result->savedAmount, 2),
            $result->savedPercent,
        ));

        if (! $result->assignmentsMatch) {
            $this->error('Coverage changed between runs — this should never happen, investigate!');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
