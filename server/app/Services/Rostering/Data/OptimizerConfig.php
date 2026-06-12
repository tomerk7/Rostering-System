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
        /** Skip optimization when filled/total positions is below this rate. */
        // TODO change to only apply optimizer for fully generated roster 
        public float $coverageThreshold = 0.85,
        /** Cost (in currency units) traded per hour of min-hours shortfall. */
        public float $lambda = 55.0,
        public float $initialTemperature = 100.0,
        /** Geometric cooling factor applied once per iteration. */
        public float $coolingRate = 0.999,
        // public float $minTemperature = 0.01,
        public float $minTemperature = 0.1,
        public int $maxIterations = 30_000,
        /** Fixed RNG seed so identical input yields an identical roster. */
        public int $seed = 2026,
    ) {}
}
