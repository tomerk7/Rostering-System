<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering\Data;

use App\Services\Rostering\Data\OptimizerConfig;
use PHPUnit\Framework\TestCase;

final class OptimizerConfigTest extends TestCase
{
    public function testWithBalanceWeight(): void
    {
        $base = new OptimizerConfig(
            coverageThreshold: 0.9,
            shortfallPenaltyPerHour: 12.5,
            balanceWeight: 20.0,
            initialTemperature: 50.0,
            coolingRate: 0.95,
            minTemperature: 0.2,
            maxIterations: 1000,
            seed: 7,
        );

        $derived = $base->withBalanceWeight(80.0);

        // Only balanceWeight changes...
        $this->assertSame(80.0, $derived->balanceWeight);
        // ...every other tunable is carried over unchanged.
        $this->assertSame(0.9, $derived->coverageThreshold);
        $this->assertSame(12.5, $derived->shortfallPenaltyPerHour);
        $this->assertSame(50.0, $derived->initialTemperature);
        $this->assertSame(0.95, $derived->coolingRate);
        $this->assertSame(0.2, $derived->minTemperature);
        $this->assertSame(1000, $derived->maxIterations);
        $this->assertSame(7, $derived->seed);

        // A new instance is returned and the original is left untouched (readonly).
        $this->assertNotSame($base, $derived);
        $this->assertSame(20.0, $base->balanceWeight);
    }

    public function testWithShortfallPenaltyPerHour(): void
    {
        $base = new OptimizerConfig(
            coverageThreshold: 0.9,
            shortfallPenaltyPerHour: 45.0,
            balanceWeight: 30.0,
            initialTemperature: 50.0,
            coolingRate: 0.95,
            minTemperature: 0.2,
            maxIterations: 1000,
            seed: 7,
        );

        $derived = $base->withShortfallPenaltyPerHour(99.0);

        $this->assertSame(99.0, $derived->shortfallPenaltyPerHour);
        $this->assertSame(0.9, $derived->coverageThreshold);
        $this->assertSame(30.0, $derived->balanceWeight);
        $this->assertSame(50.0, $derived->initialTemperature);
        $this->assertSame(0.95, $derived->coolingRate);
        $this->assertSame(0.2, $derived->minTemperature);
        $this->assertSame(1000, $derived->maxIterations);
        $this->assertSame(7, $derived->seed);

        $this->assertNotSame($base, $derived);
        $this->assertSame(45.0, $base->shortfallPenaltyPerHour);
    }
}
