<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Rostering\OptimizerPenaltyAdvisor;
use Illuminate\Console\Command;
use RuntimeException;

final class RecommendOptimizerPenalties extends Command
{
    protected $signature = 'roster:recommend-penalties
        {--year= : Target year; defaults to the current year}
        {--month= : Target month (1-12); defaults to the current month}
        {--shortfall-margin=1.10 : Safety multiplier above the calculated break-even}
        {--balance-gap=3 : Excess-shift gap that the balanced preset should be willing to correct}
        {--json : Emit machine-readable JSON}';

    protected $description = 'Recommend data-driven simulated-annealing penalty values without changing configuration.';

    public function handle(OptimizerPenaltyAdvisor $advisor): int
    {
        $year = $this->option('year') === null ? (int) now()->year : (int) $this->option('year');
        $month = $this->option('month') === null ? (int) now()->month : (int) $this->option('month');
        $shortfallMargin = (float) $this->option('shortfall-margin');
        $balanceGap = (int) $this->option('balance-gap');

        if ($year < 2000 || $year > 2100) {
            $this->error('The year must be between 2000 and 2100.');

            return self::INVALID;
        }

        if ($month < 1 || $month > 12) {
            $this->error('The month must be between 1 and 12.');

            return self::INVALID;
        }

        if ($shortfallMargin < 1.0) {
            $this->error('The shortfall margin must be at least 1.0.');

            return self::INVALID;
        }

        if ($balanceGap < 2) {
            $this->error('The balance gap must be at least 2 shifts.');

            return self::INVALID;
        }

        try {
            $report = $advisor->recommend($year, $month, $shortfallMargin, $balanceGap);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ));

            return self::SUCCESS;
        }

        $this->components->info("Optimizer penalty recommendations for {$year}-".str_pad((string) $month, 2, '0', STR_PAD_LEFT));
        $this->line(sprintf(
            'Based on %d active contracts, %d co-eligible worker pairs, and a %.2fh typical shift.',
            $report['context']['active_contracts'],
            $report['context']['co_eligible_worker_pairs'],
            $report['context']['typical_shift_hours'],
        ));

        $penaltyRows = [[
            'shortfallPenaltyPerHour',
            $this->number($report['current']['shortfall_penalty_per_hour']),
            $this->number($report['recommended']['shortfall_penalty_per_hour']),
            'Protects minimum hours against wage and balance savings',
        ]];

        foreach ($report['recommended']['balance_weights'] as $preference => $recommended) {
            $penaltyRows[] = [
                "balanceWeight: {$preference}",
                $this->number($report['current']['balance_weights'][$preference]),
                $this->number($recommended),
                $preference === 'maximum_savings'
                    ? 'Intentionally ignores distribution'
                    : 'Trades payroll cost for a flatter excess-hours spread',
            ];
        }

        $this->newLine();
        $this->table(['Setting', 'Current', 'Recommended', 'Meaning'], $penaltyRows);

        $this->line(sprintf(
            'Observed wage differences: p75 %.2f/hour, maximum %.2f/hour. Shortfall break-even: %.2f/hour.',
            $report['wage_analysis']['p75_co_eligible_hourly_difference'],
            $report['wage_analysis']['maximum_co_eligible_hourly_difference'],
            $report['wage_analysis']['shortfall_break_even_per_hour'],
        ));

        $this->newLine();
        $this->components->twoColumnDetail('Capacity check', 'before availability constraints');
        $this->table(
            ['Role', 'Demand h', 'Min h', 'Max h', 'Status'],
            array_map(
                static fn (array $row): array => [
                    $row['role'],
                    $row['demand_hours'],
                    $row['contract_min_hours'],
                    $row['contract_max_hours'],
                    $row['status'],
                ],
                $report['capacity'],
            ),
        );

        foreach ($report['warnings'] as $warning) {
            $this->components->warn($warning);
        }

        $this->newLine();
        $this->line('Apply the shortfall value in app/Services/Rostering/Data/OptimizerConfig.php.');
        $this->line('Apply distribution values in app/Services/Rostering/Data/DistributionPreference.php.');
        $this->comment('These are break-even baselines. Validate the selected trade-off with the roster benchmark before deployment.');

        return self::SUCCESS;
    }

    private function number(float|int $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
}
