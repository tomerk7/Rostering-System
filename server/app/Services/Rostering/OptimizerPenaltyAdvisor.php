<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Services\Rostering\Data\DistributionPreference;
use App\Services\Rostering\Data\OptimizerConfig;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Derive optimizer penalty baselines from the workforce and demand currently
 * stored in the database. Recommendations are advisory and never mutate config.
 */
final readonly class OptimizerPenaltyAdvisor
{
    private const float ROUNDING_STEP = 5.0;

    private const int BALANCE_SHIFT_HOURS = 8;

    /**
     * @return array<string, mixed>
     */
    public function recommend(int $year, int $month, float $shortfallMargin = 1.10, int $balanceGap = 3): array
    {
        $contracts = DB::table('contracts')
            ->join('workers', 'workers.israeli_id', '=', 'contracts.worker_id')
            ->join('roles', 'roles.id', '=', 'workers.role_id')
            ->where('workers.is_active', true)
            ->whereNull('workers.deleted_at')
            ->select([
                'contracts.id',
                'contracts.worker_id',
                'contracts.hourly_cost',
                'contracts.min_monthly_hours',
                'contracts.max_monthly_hours',
                'workers.role_id',
                'roles.name as role_name',
            ])
            ->orderBy('workers.role_id')
            ->orderBy('contracts.worker_id')
            ->get();

        if ($contracts->isEmpty()) {
            throw new RuntimeException('No active worker contracts were found.');
        }

        $requirements = DB::table('shift_role_requirements')
            ->join('shifts', 'shifts.id', '=', 'shift_role_requirements.shift_id')
            ->join('roles', 'roles.id', '=', 'shift_role_requirements.role_id')
            ->where('shift_role_requirements.required_count', '>', 0)
            ->select([
                'shift_role_requirements.role_id',
                'shift_role_requirements.shift_id',
                'shift_role_requirements.required_count',
                'shifts.duration_hours',
                'roles.name as role_name',
            ])
            ->orderBy('shift_role_requirements.role_id')
            ->orderBy('shift_role_requirements.shift_id')
            ->get();

        if ($requirements->isEmpty()) {
            throw new RuntimeException('No positive shift-role requirements were found.');
        }

        $availability = [];

        foreach (DB::table('contract_availability')
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->select(['contract_id', 'day_of_week', 'shift_id'])
            ->get() as $row) {
            $availability[(int) $row->contract_id][(int) $row->day_of_week.':'.(int) $row->shift_id] = true;
        }

        $contractsByRole = [];
        $roleNames = [];
        $contractsWithoutAvailability = 0;

        foreach ($contracts as $contract) {
            $contractId = (int) $contract->id;
            $roleId = (int) $contract->role_id;
            $roleNames[$roleId] = (string) $contract->role_name;

            if (($availability[$contractId] ?? []) === []) {
                $contractsWithoutAvailability++;
            }

            $contractsByRole[$roleId][] = [
                'id' => $contractId,
                'worker_id' => (string) $contract->worker_id,
                'hourly_cost' => (float) $contract->hourly_cost,
                'min_hours' => (int) $contract->min_monthly_hours,
                'max_hours' => (int) $contract->max_monthly_hours,
                'availability' => $availability[$contractId] ?? [],
            ];
        }

        [$roleSpreads, $hourlyPremiums, $coEligiblePairs] = $this->wageDifferences($contractsByRole);
        $typicalHourlyPremium = $this->percentile($hourlyPremiums, 0.75);
        $maximumHourlySpread = $roleSpreads === [] ? 0.0 : max($roleSpreads);

        $totalRequiredPositions = 0;
        $weightedDurationHours = 0;
        $requirementsByRole = [];
        $durations = [];

        foreach ($requirements as $requirement) {
            $roleId = (int) $requirement->role_id;
            $requiredCount = (int) $requirement->required_count;
            $durationHours = (int) $requirement->duration_hours;
            $roleNames[$roleId] = (string) $requirement->role_name;
            $totalRequiredPositions += $requiredCount;
            $weightedDurationHours += $requiredCount * $durationHours;
            $durations[$durationHours] = true;
            $requirementsByRole[$roleId][] = [
                'shift_id' => (int) $requirement->shift_id,
                'required_count' => $requiredCount,
                'duration_hours' => $durationHours,
            ];
        }

        $typicalShiftHours = $weightedDurationHours / $totalRequiredPositions;
        $balanceReduction = 2 * ($balanceGap - 1);
        $balancedWeight = $coEligiblePairs === 0
            ? 0.0
            : max(
                self::ROUNDING_STEP,
                $this->roundUp(($typicalHourlyPremium * $typicalShiftHours) / $balanceReduction),
            );

        $recommendedBalanceWeights = [
            DistributionPreference::MaximumSavings->value => 0.0,
            DistributionPreference::CostFocused->value => $balancedWeight === 0.0
                ? 0.0
                : max(self::ROUNDING_STEP, $this->roundUp($balancedWeight * 0.5)),
            DistributionPreference::Balanced->value => $balancedWeight,
            DistributionPreference::DistributionFocused->value => $this->roundUp($balancedWeight * 2.0),
        ];

        $shortfallBreakEven = $this->shortfallBreakEven(
            $contractsByRole,
            $requirementsByRole,
            $roleSpreads,
            $recommendedBalanceWeights[DistributionPreference::DistributionFocused->value],
        );
        $hasContractMinimums = $contracts->contains(
            static fn (object $contract): bool => (int) $contract->min_monthly_hours > 0,
        );
        $recommendedShortfallPenalty = $hasContractMinimums
            ? max(self::ROUNDING_STEP, $this->roundUp($shortfallBreakEven * $shortfallMargin))
            : 0.0;

        $daysInMonth = (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        $capacity = $this->capacityRows(
            $roleNames,
            $contractsByRole,
            $requirementsByRole,
            $daysInMonth,
        );

        $warnings = [];

        if ($contractsWithoutAvailability > 0) {
            $warnings[] = "{$contractsWithoutAvailability} active contract(s) have no availability and cannot receive assignments.";
        }

        if (array_keys($durations) !== [self::BALANCE_SHIFT_HOURS]) {
            $warnings[] = 'SAOptimizer balance units are hard-coded to 8-hour shifts; update SHIFT_HOURS before trusting balance recommendations for mixed durations.';
        }

        foreach ($capacity as $row) {
            if ($row['status'] !== 'ok') {
                $warnings[] = $row['message'];
            }
        }

        $config = new OptimizerConfig;

        return [
            'context' => [
                'year' => $year,
                'month' => $month,
                'days_in_month' => $daysInMonth,
                'active_contracts' => $contracts->count(),
                'co_eligible_worker_pairs' => $coEligiblePairs,
                'typical_shift_hours' => round($typicalShiftHours, 2),
                'balance_gap_shifts' => $balanceGap,
                'shortfall_safety_margin' => $shortfallMargin,
            ],
            'wage_analysis' => [
                'p75_co_eligible_hourly_difference' => round($typicalHourlyPremium, 2),
                'maximum_co_eligible_hourly_difference' => round($maximumHourlySpread, 2),
                'shortfall_break_even_per_hour' => round($shortfallBreakEven, 2),
            ],
            'current' => [
                'shortfall_penalty_per_hour' => $config->shortfallPenaltyPerHour,
                'balance_weights' => $this->currentBalanceWeights(),
            ],
            'recommended' => [
                'shortfall_penalty_per_hour' => $recommendedShortfallPenalty,
                'balance_weights' => $recommendedBalanceWeights,
            ],
            'capacity' => $capacity,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<int, list<array<string, mixed>>>  $contractsByRole
     * @return array{0: array<int, float>, 1: list<float>, 2: int}
     */
    private function wageDifferences(array $contractsByRole): array
    {
        $roleSpreads = [];
        $hourlyPremiums = [];
        $pairCount = 0;

        foreach ($contractsByRole as $roleId => $contracts) {
            $roleSpreads[$roleId] = 0.0;

            for ($left = 0; $left < count($contracts); $left++) {
                for ($right = $left + 1; $right < count($contracts); $right++) {
                    if (! $this->availabilityOverlaps($contracts[$left]['availability'], $contracts[$right]['availability'])) {
                        continue;
                    }

                    $pairCount++;
                    $difference = abs($contracts[$left]['hourly_cost'] - $contracts[$right]['hourly_cost']);
                    $roleSpreads[$roleId] = max($roleSpreads[$roleId], $difference);

                    if ($difference > 0.0) {
                        $hourlyPremiums[] = $difference;
                    }
                }
            }
        }

        return [$roleSpreads, $hourlyPremiums, $pairCount];
    }

    /**
     * Estimate the strongest objective improvement that could be obtained per
     * newly-created shortfall hour. This includes both wage savings and the
     * balance reward under the strongest recommended distribution preset.
     *
     * @param  array<int, list<array<string, mixed>>>  $contractsByRole
     * @param  array<int, list<array{shift_id: int, required_count: int, duration_hours: int}>>  $requirementsByRole
     * @param  array<int, float>  $roleSpreads
     */
    private function shortfallBreakEven(
        array $contractsByRole,
        array $requirementsByRole,
        array $roleSpreads,
        float $maximumBalanceWeight,
    ): float {
        $breakEven = 0.0;

        foreach ($contractsByRole as $roleId => $contracts) {
            $roleRequirements = $requirementsByRole[$roleId] ?? [];

            if ($roleRequirements === []) {
                continue;
            }

            $quantum = $this->greatestCommonDivisor(array_column($roleRequirements, 'duration_hours'));

            foreach ($contracts as $contract) {
                if ($contract['min_hours'] <= 0) {
                    continue;
                }

                foreach ($roleRequirements as $requirement) {
                    if (! $this->availableForShift($contract['availability'], $requirement['shift_id'])) {
                        continue;
                    }

                    $duration = $requirement['duration_hours'];
                    $hoursBeforeRemoval = intdiv($contract['min_hours'] + $duration - 1, $quantum) * $quantum;
                    $shortfallHours = $contract['min_hours'] - ($hoursBeforeRemoval - $duration);

                    if ($shortfallHours <= 0) {
                        continue;
                    }

                    $excessHours = max(0, $hoursBeforeRemoval - $contract['min_hours']);
                    $wageSaving = ($roleSpreads[$roleId] ?? 0.0) * $duration;
                    $balanceSaving = $maximumBalanceWeight * (($excessHours / self::BALANCE_SHIFT_HOURS) ** 2);
                    $breakEven = max($breakEven, ($wageSaving + $balanceSaving) / $shortfallHours);
                }
            }
        }

        return $breakEven;
    }

    /**
     * @param  array<int, string>  $roleNames
     * @param  array<int, list<array<string, mixed>>>  $contractsByRole
     * @param  array<int, list<array{shift_id: int, required_count: int, duration_hours: int}>>  $requirementsByRole
     * @return list<array<string, int|string>>
     */
    private function capacityRows(
        array $roleNames,
        array $contractsByRole,
        array $requirementsByRole,
        int $daysInMonth,
    ): array {
        ksort($roleNames);
        $rows = [];

        foreach ($roleNames as $roleId => $roleName) {
            $demandHours = 0;

            foreach ($requirementsByRole[$roleId] ?? [] as $requirement) {
                $demandHours += $requirement['required_count'] * $requirement['duration_hours'] * $daysInMonth;
            }

            $minimumHours = array_sum(array_column($contractsByRole[$roleId] ?? [], 'min_hours'));
            $maximumHours = array_sum(array_column($contractsByRole[$roleId] ?? [], 'max_hours'));
            $status = 'ok';
            $message = "{$roleName}: contract-hour capacity is compatible with demand.";

            if ($minimumHours > $demandHours) {
                $status = 'minimum_shortfall_unavoidable';
                $message = "{$roleName}: contracted minimums exceed monthly demand by ".($minimumHours - $demandHours).'h; penalties cannot eliminate all shortfalls.';
            } elseif ($maximumHours < $demandHours) {
                $status = 'coverage_impossible';
                $message = "{$roleName}: monthly demand exceeds contract maximums by ".($demandHours - $maximumHours).'h before availability constraints.';
            }

            $rows[] = [
                'role' => $roleName,
                'demand_hours' => $demandHours,
                'contract_min_hours' => $minimumHours,
                'contract_max_hours' => $maximumHours,
                'status' => $status,
                'message' => $message,
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, float>
     */
    private function currentBalanceWeights(): array
    {
        $weights = [];

        foreach (DistributionPreference::cases() as $preference) {
            $weights[$preference->value] = $preference->balanceWeight();
        }

        return $weights;
    }

    /**
     * @param  array<string, true>  $left
     * @param  array<string, true>  $right
     */
    private function availabilityOverlaps(array $left, array $right): bool
    {
        foreach ($left as $key => $_) {
            if (isset($right[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, true>  $availability
     */
    private function availableForShift(array $availability, int $shiftId): bool
    {
        foreach ($availability as $key => $_) {
            if (str_ends_with($key, ':'.$shiftId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $percentile): float
    {
        if ($values === []) {
            return 0.0;
        }

        sort($values, SORT_NUMERIC);
        $index = max(0, (int) ceil($percentile * count($values)) - 1);

        return $values[$index];
    }

    /**
     * @param  list<int>  $values
     */
    private function greatestCommonDivisor(array $values): int
    {
        $result = array_shift($values) ?? self::BALANCE_SHIFT_HOURS;

        foreach ($values as $value) {
            while ($value !== 0) {
                [$result, $value] = [$value, $result % $value];
            }
        }

        return max(1, $result);
    }

    private function roundUp(float $value): float
    {
        if ($value <= 0.0) {
            return 0.0;
        }

        return ceil(($value - 1e-9) / self::ROUNDING_STEP) * self::ROUNDING_STEP;
    }
}
