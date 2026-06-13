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
     * The optimizer balanceWeight for this preference. Higher values penalise
     * concentrating shifts on one worker more steeply. Magnitudes are initial
     * tuning values — adjust here as one place.
     * 
     * @return float
     */
    public function balanceWeight(): float
    {
        return match ($this) {
            self::MaximumSavings => 0.0,
            self::CostFocused => 10.0,
            self::Balanced => 30.0,
            self::DistributionFocused => 80.0,
        };
    }
}
