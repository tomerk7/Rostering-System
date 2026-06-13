<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Rostering\Data\DistributionPreference;

/**
 * Resolves the cost-optimization flag and balance weight from a roster
 * generation request. A distribution preference implies optimization and
 * maps to its balance weight; otherwise the raw optimize_cost flag is used.
 */
trait ResolvesOptimization
{
    /**
     * @return array{0: bool, 1: float|null}
     */
    public function optimization(): array
    {
        $preference = $this->validated('distribution_preference');

        if ($preference !== null) {
            return [true, DistributionPreference::from($preference)->balanceWeight()];
        }

        return [$this->boolean('optimize_cost'), null];
    }
}
