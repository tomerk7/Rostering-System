<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

use Carbon\CarbonImmutable;

/**
 * One staffing demand position for a (date, shift, role) combination.
 */
final readonly class RosterSlot
{
    public function __construct(
        public CarbonImmutable $workDate,
        public int $shiftId,
        public int $roleId,
        public int $requiredCount,
        public int $durationHours,
    ) {}
}
