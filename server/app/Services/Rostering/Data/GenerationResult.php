<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

use Carbon\CarbonImmutable;

/**
 * The transient output of a generation run: the planned assignments plus the two
 * structured shortage reports, surfaced as a preview before the roster is saved.
 *
 * This is the one value object the engine keeps, because "the result of a
 * generation" is a real business concept the orchestrator and API hand around as
 * a unit. Its three payloads are plain arrays:
 *
 *   assignments        => list<array{worker_id: int, shift_id: int, work_date: CarbonImmutable, source: string}>
 *   coverageShortages  => list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>
 *   hoursShortfalls    => list<array{worker_id: int, min_hours: int, scheduled_hours: int}>
 *   feasibilityIssues  => list<array{role_id: int, work_date: CarbonImmutable, shift_id: int, required_workers: int, available_workers: int}>
 */
final readonly class GenerationResult
{
    /**
     * @param  list<array{worker_id: int, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>  $coverageShortages
     * @param  list<array{worker_id: int, min_hours: int, scheduled_hours: int}>  $hoursShortfalls
     * @param  list<array{role_id: int, work_date: CarbonImmutable, shift_id: int, required_workers: int, available_workers: int}>  $feasibilityIssues
     */
    public function __construct(
        public int $year,
        public int $month,
        public array $assignments,
        public array $coverageShortages,
        public array $hoursShortfalls,
        public array $feasibilityIssues = [],
    ) {}

    public function hasCoverageShortages(): bool
    {
        return $this->coverageShortages !== [];
    }

    public function hasHoursShortfalls(): bool
    {
        return $this->hoursShortfalls !== [];
    }

    public function hasFeasibilityIssues(): bool
    {
        return $this->feasibilityIssues !== [];
    }
}
