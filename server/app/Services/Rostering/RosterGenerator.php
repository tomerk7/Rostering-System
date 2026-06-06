<?php

declare(strict_types=1);

namespace App\Services\Rostering;

use App\Services\Rostering\Data\GenerationResult;

/**
 * Orchestrates one rostering run end to end: expand the month's demand, load the
 * eligible workforce, run the greedy engine, then assemble the pre-save preview —
 * the planned assignments plus the coverage-shortage and hours-shortfall reports —
 * into a single GenerationResult.
 *
 * No persistence happens here. Surfacing both reports before saving lets an admin
 * review what could not be satisfied and decide whether to save/publish; writing
 * the roster is a separate, explicit step.
 */
final readonly class RosterGenerator
{
    public function __construct(
        private DemandBuilder $demandBuilder,
        private EligibilityResolver $eligibilityResolver,
        private RosteringEngine $engine,
    ) {}

    /**
     * Generate the roster preview for a target month.
     */
    public function generate(int $year, int $month): GenerationResult
    {
        $slots = $this->demandBuilder->build($year, $month);
        $workers = $this->eligibilityResolver->resolve();

        // The engine mutates the worker counters in place as it places
        // assignments, so $workers afterwards holds the final scheduled hours the
        // coverage and shortfall reports read from.
        $assignments = $this->engine->generate($slots, $workers);

        return new GenerationResult(
            year: $year,
            month: $month,
            assignments: $assignments,
            coverageShortages: $this->engine->validateCoverage($slots, $assignments, $workers),
            hoursShortfalls: $this->engine->reportHoursShortfalls($workers),
        );
    }
}
