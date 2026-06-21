<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering;

use App\Services\Rostering\Data\OptimizerConfig;
use App\Services\Rostering\Data\RosterSlot;
use App\Services\Rostering\Data\RosterWorker;
use App\Services\Rostering\RosteringEngine;
use App\Services\Rostering\SAOptimizer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class SAOptimizerTest extends TestCase
{
    private const SUPERVISOR = 1;
    private const SHIFT = 10;
    private const DURATION = 8;

    /**
     * Build a fresh, self-consistent scenario: two supervisor positions on two
     * dates and two eligible workers. The greedy engine's lowest-id tie-break
     * forces the expensive worker ("aaa") into both positions, leaving the cheap
     * worker ("zzz") on the bench for the optimizer to substitute in.
     *
     * @return array{list<RosterSlot>, array<string, RosterWorker>, list<array<string, mixed>>, float}
     */
    private function scenario(): array
    {
        $d1 = CarbonImmutable::parse('2026-01-01');
        $d2 = CarbonImmutable::parse('2026-01-02');
        $availability = [
            $d1->dayOfWeek => [self::SHIFT => true],
            $d2->dayOfWeek => [self::SHIFT => true],
        ];

        $workers = [
            'aaa' => new RosterWorker(roleId: self::SUPERVISOR, hourlyCost: 100.0, minHours: 0, maxHours: 100, availability: $availability),
            'zzz' => new RosterWorker(roleId: self::SUPERVISOR, hourlyCost: 10.0, minHours: 0, maxHours: 100, availability: $availability),
        ];

        $slots = [
            new RosterSlot(workDate: $d1, shiftId: self::SHIFT, roleId: self::SUPERVISOR, requiredCount: 1, durationHours: self::DURATION),
            new RosterSlot(workDate: $d2, shiftId: self::SHIFT, roleId: self::SUPERVISOR, requiredCount: 1, durationHours: self::DURATION),
        ];

        $engine = new RosteringEngine();
        $assignments = $engine->generate($slots, $workers);

        return [$slots, $workers, $assignments, $this->cost($assignments, $workers)];
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     * @param  array<string, RosterWorker>  $workers
     */
    private function cost(array $assignments, array $workers): float
    {
        $total = 0.0;
        foreach ($assignments as $row) {
            $total += $workers[$row['worker_id']]->hourlyCost * self::DURATION;
        }

        return $total;
    }

    private function config(): OptimizerConfig
    {
        // Pure cost objective: no shortfall (min 0) and no balance penalty, so the
        // optimizer's only lever is swapping in the cheaper eligible worker.
        return new OptimizerConfig(
            coverageThreshold: 1.0,
            shortfallPenaltyPerHour: 0.0,
            balanceWeight: 0.0,
        );
    }

    public function testOptimize(): void
    {
        [$slots, $workers, $assignments, $initialCost] = $this->scenario();

        // Sanity: greedy really did load the expensive worker into both slots.
        $this->assertSame(['aaa', 'aaa'], array_column($assignments, 'worker_id'));

        $optimizer = new SAOptimizer(new RosteringEngine(), $this->config());
        $optimized = $optimizer->optimize($slots, $workers, $assignments);

        // Coverage is invariant: the optimizer only changes WHO fills a position.
        $this->assertCount(count($assignments), $optimized);

        // Cost strictly improved by substituting the cheaper eligible worker.
        $optimizedCost = $this->cost($optimized, $workers);
        $this->assertLessThan($initialCost, $optimizedCost);

        // Every produced row still names an eligible supervisor.
        foreach ($optimized as $row) {
            $this->assertContains($row['worker_id'], ['aaa', 'zzz']);
        }

        // Determinism: the same seeded run on a fresh scenario yields the same result.
        [$slots2, $workers2, $assignments2] = $this->scenario();
        $rerun = (new SAOptimizer(new RosteringEngine(), $this->config()))
            ->optimize($slots2, $workers2, $assignments2);
        $this->assertSame(
            array_column($optimized, 'worker_id'),
            array_column($rerun, 'worker_id'),
        );
    }
}
