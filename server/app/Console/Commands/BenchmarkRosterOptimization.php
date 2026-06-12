<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Rostering\RosterGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

    public function handle(RosterGenerator $generator): int
    {
        $year = (int) $this->argument('year');
        $month = (int) $this->argument('month');

        $costs = DB::table('contracts')->pluck('hourly_cost', 'worker_id');
        $minHours = DB::table('contracts')->pluck('min_monthly_hours', 'worker_id');
        $maxHours = DB::table('contracts')->pluck('max_monthly_hours', 'worker_id');

        if ($costs->isEmpty()) {
            $this->error('No contracts found — seed some workers first.');

            return self::FAILURE;
        }

        $this->info("Generating {$year}-{$month} twice (plain, then optimized)...");

        $startedAt = microtime(true);
        $plain = $generator->generate($year, $month);
        $plainSeconds = microtime(true) - $startedAt;

        $startedAt = microtime(true);
        $optimized = $generator->generate($year, $month, optimizeCost: true);
        $optimizedSeconds = microtime(true) - $startedAt;

        $totalCost = static function (array $assignments) use ($costs): float {
            $total = 0.0;

            foreach ($assignments as $assignment) {
                $total += (float) $costs[$assignment['worker_id']] * 8;
            }

            return $total;
        };

        $scheduledHours = static function (array $assignments): array {
            $scheduled = [];

            foreach ($assignments as $assignment) {
                $scheduled[$assignment['worker_id']] = ($scheduled[$assignment['worker_id']] ?? 0) + 8;
            }

            return $scheduled;
        };

        $shortfallHours = static function (array $assignments) use ($minHours, $scheduledHours): int {
            $scheduled = $scheduledHours($assignments);
            $total = 0;

            foreach ($minHours as $workerId => $min) {
                $total += max(0, (int) $min - ($scheduled[$workerId] ?? 0));
            }

            return $total;
        };

        $maxViolations = static function (array $assignments) use ($maxHours, $scheduledHours): int {
            $scheduled = $scheduledHours($assignments);
            $count = 0;

            foreach ($maxHours as $workerId => $max) {
                if (($scheduled[$workerId] ?? 0) > (int) $max) {
                    $count++;
                }
            }

            return $count;
        };

        $hoursStdDev = static function (array $assignments) use ($scheduledHours): float {
            $scheduled = array_values($scheduledHours($assignments));

            if (count($scheduled) < 2) {
                return 0.0;
            }

            $mean = array_sum($scheduled) / count($scheduled);
            $variance = array_sum(array_map(fn ($h) => ($h - $mean) ** 2, $scheduled)) / count($scheduled);

            return sqrt($variance);
        };

        $costPlain = $totalCost($plain->assignments);
        $costOptimized = $totalCost($optimized->assignments);
        $saved = $costPlain - $costOptimized;

        $this->table(
            ['Metric', 'Plain (greedy)', 'Optimized (greedy + SA)'],
            [
                ['Assignments', count($plain->assignments), count($optimized->assignments)],
                ['Coverage shortages', count($plain->coverageShortages), count($optimized->coverageShortages)],
                ['Total cost', number_format($costPlain, 2), number_format($costOptimized, 2)],
                ['Min-hours shortfall (workers)', count($plain->hoursShortfalls), count($optimized->hoursShortfalls)],
                ['Min-hours shortfall (hours)', $shortfallHours($plain->assignments), $shortfallHours($optimized->assignments)],
                ['Max-hours violations (workers)', $maxViolations($plain->assignments), $maxViolations($optimized->assignments)],
                ['Hours std deviation', sprintf('%.2f', $hoursStdDev($plain->assignments)), sprintf('%.2f', $hoursStdDev($optimized->assignments))],
                ['Generation time', sprintf('%.2fs', $plainSeconds), sprintf('%.2fs', $optimizedSeconds)],
            ],
        );

        $this->info(sprintf(
            'Saved: %s (%.2f%%)',
            number_format($saved, 2),
            $costPlain > 0 ? ($saved / $costPlain) * 100 : 0,
        ));

        if (count($plain->assignments) !== count($optimized->assignments)) {
            $this->error('Coverage changed between runs — this should never happen, investigate!');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
