<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering\Data;

use App\Services\Rostering\Data\DistributionPreference;
use PHPUnit\Framework\TestCase;

final class DistributionPreferenceTest extends TestCase
{
    public function testBalanceToleranceShifts(): void
    {
        // Each preset maps to how many extra shifts a worker may take before the
        // advisor's balance penalty starts pushing back (null = never).
        $this->assertNull(DistributionPreference::MaximumSavings->balanceToleranceShifts());
        $this->assertSame(10, DistributionPreference::CostFocused->balanceToleranceShifts());
        $this->assertSame(4, DistributionPreference::Balanced->balanceToleranceShifts());
        $this->assertSame(1, DistributionPreference::DistributionFocused->balanceToleranceShifts());
    }

    public function testValues(): void
    {
        $this->assertSame(
            ['maximum_savings', 'cost_focused', 'balanced', 'distribution_focused'],
            DistributionPreference::values(),
        );
    }
}
