<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

use Carbon\CarbonImmutable;

/**
 * The transient output of a generation run: the planned assignments plus the two
 * structured shortage reports, surfaced as a preview before the roster is saved.
 */
final readonly class GenerationResult
{
    /**
     * @param  list<array{worker_id: string, shift_id: int, work_date: CarbonImmutable, source: string}>  $assignments
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>  $coverageShortages
     * @param  list<array{worker_id: string, min_hours: int, scheduled_hours: int}>  $hoursShortfalls
     */
    public function __construct(
        public int $year,
        public int $month,
        public array $assignments,
        public array $coverageShortages,
        public array $hoursShortfalls,
    ) {}

    public function hasCoverageShortages(): bool
    {
        return $this->coverageShortages !== [];
    }

    public function hasHoursShortfalls(): bool
    {
        return $this->hoursShortfalls !== [];
    }
}
