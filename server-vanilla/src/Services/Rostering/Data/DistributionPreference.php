<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

/**
 * The scheduler-facing trade-off between payroll cost and an even spread of
 * hours, exposed as four labelled presets instead of a raw optimizer weight.
 */
enum DistributionPreference: string
{
    case MaximumSavings = 'maximum_savings';
    case CostFocused = 'cost_focused';
    case Balanced = 'balanced';
    case DistributionFocused = 'distribution_focused';

    /**
     * Extra shifts above minimum before balance penalty outweighs wage savings.
     * null = pure cost; smaller tolerance pushes toward a more even spread.
     *
     * @return int|null
     */
    public function balanceToleranceShifts(): ?int
    {
        return match ($this) {
            self::MaximumSavings => null,
            self::CostFocused => 10,
            self::Balanced => 4,
            self::DistributionFocused => 1,
        };
    }

    /**
     * The backing values for every preference (for the `in:` validation rule).
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p): string => $p->value, self::cases());
    }
}
