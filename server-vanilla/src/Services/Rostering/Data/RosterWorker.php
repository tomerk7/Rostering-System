<?php

declare(strict_types=1);

namespace App\Services\Rostering\Data;

/**
 * The live worker state the rostering engine reads and mutates during construction.
 */
final class RosterWorker
{
    /**
     * Class constructor.
     *
     * @param int $roleId
     * @param float $hourlyCost
     * @param int $minHours
     * @param int $maxHours
     * @param array<int, array<int, true>> $availability
     * @param int $assignedHours
     * @param array<string, int> $shiftsPerDate
     */
    public function __construct(
        public readonly int $roleId,
        public readonly float $hourlyCost = 0.0,
        public readonly int $minHours = 0,
        public readonly int $maxHours = 0,
        public readonly array $availability = [],
        public int $assignedHours = 0,
        public array $shiftsPerDate = [],
    ) {}
}
