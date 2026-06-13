<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

/**
 * Tunables for the simulated annealing cost-optimization phase.
 *
 * Held in one value object so tests can construct tiny or extreme
 * configurations without touching global state.
 */
final readonly class OptimizerConfig
{
    public function __construct(
        /** Skip optimization unless every required position is filled. */
        public float $coverageThreshold = 1.0,
        /** Cost (in currency units) traded per hour of min-hours shortfll. */
        public float $shortfallPenaltyPerHour = 45.0,
        /**
         * Weight on the squared count of shifts a worker is scheduled above
         * their minimum. 0 = pure cost + shortfall; higher trades payroll for a
         * flatter spread of hours. Set per run via the distribution preference.
         */
        public float $balanceWeight = 20.0,
        public float $initialTemperature = 100.0,
        /** Geometric cooling factor applied once per iteration. */
        public float $coolingRate = 0.999,
        // public float $minTemperature = 0.01,
        public float $minTemperature = 0.1,
        public int $maxIterations = 30_000,
        /** Fixed RNG seed so identical input yields an identical roster. */
        public int $seed = 2026,
    ) {}

    /**
     * Return a copy of this config with balanceWeight replaced, leaving every
     * other tunable intact. Lets a single base config be reused across runs at
     * different distribution preferences.
     * 
     * @param float $balanceWeight
     * @return self
     */
    public function withBalanceWeight(float $balanceWeight): self
    {
        return new self(
            coverageThreshold: $this->coverageThreshold,
            shortfallPenaltyPerHour: $this->shortfallPenaltyPerHour,
            balanceWeight: $balanceWeight,
            initialTemperature: $this->initialTemperature,
            coolingRate: $this->coolingRate,
            minTemperature: $this->minTemperature,
            maxIterations: $this->maxIterations,
            seed: $this->seed,
        );
    }
}
