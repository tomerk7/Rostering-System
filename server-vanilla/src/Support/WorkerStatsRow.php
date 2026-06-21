<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Per-worker hours, cost, and shortfall for roster stats. Hours and cost are
 * derived from assignment rows whose hourly_cost was snapshotted at creation
 * time, so historical stats never change when a contract rate is updated later.
 */
final readonly class WorkerStatsRow
{
    /**
     * Class constructor.
     *
     * @param string $workerId
     * @param string $name
     * @param string $role
     * @param int $minHours
     * @param int $maxHours
     * @param int $actualHours
     * @param float $totalCost
     * @param int $shortfallHours
     */
    public function __construct(
        public string $workerId,
        public string $name,
        public string $role,
        public int $minHours,
        public int $maxHours,
        public int $actualHours,
        public float $totalCost,
        public int $shortfallHours,
    ) {}

    /**
     * Build a row from aggregated hours and cost (rounding cost to 2 dp). Used by
     * the benchmark, where rows come from a generation preview rather than a saved
     * roster (no role is known, so it defaults to empty).
     *
     * @param string $workerId
     * @param string $name
     * @param int $minHours
     * @param int $maxHours
     * @param int $actualHours
     * @param float $totalCost
     * @param int $shortfallHours
     * @param string $role
     * @return self
     */
    public static function fromHoursAndCost(
        string $workerId,
        string $name,
        int $minHours,
        int $maxHours,
        int $actualHours,
        float $totalCost,
        int $shortfallHours,
        string $role = '',
    ): self {
        return new self(
            workerId: $workerId,
            name: $name,
            role: $role,
            minHours: $minHours,
            maxHours: $maxHours,
            actualHours: $actualHours,
            totalCost: round($totalCost, 2),
            shortfallHours: $shortfallHours,
        );
    }

    /**
     * @return array{
     *     worker_id: string,
     *     name: string,
     *     role: string,
     *     min_hours: int,
     *     max_hours: int,
     *     actual_hours: int,
     *     total_cost: float,
     *     shortfall_hours: int
     * }
     */
    public function toArray(): array
    {
        return [
            'worker_id' => $this->workerId,
            'name' => $this->name,
            'role' => $this->role,
            'min_hours' => $this->minHours,
            'max_hours' => $this->maxHours,
            'actual_hours' => $this->actualHours,
            'total_cost' => $this->totalCost,
            'shortfall_hours' => $this->shortfallHours,
        ];
    }
}
