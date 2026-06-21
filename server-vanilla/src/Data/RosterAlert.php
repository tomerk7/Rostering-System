<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A roster alert as read from the `roster_alerts` table (currently only the
 * hours-shortfall type). The worker's name is denormalized onto the row so it
 * stays readable after the worker is deleted; `worker` (nullable) is preferred
 * when still present. `toArray()` is the report-row shape used for hours shortfalls.
 */
final readonly class RosterAlert
{
    /**
     * Class constructor.
     *
     * @param int $id
     * @param int $rosterId
     * @param string $type
     * @param string $workerId
     * @param string|null $workerName
     * @param int $minHours
     * @param int $scheduledHours
     * @param Worker|null $worker
     */
    public function __construct(
        public int $id,
        public int $rosterId,
        public string $type,
        public string $workerId,
        public ?string $workerName,
        public int $minHours,
        public int $scheduledHours,
        public ?Worker $worker = null,
    ) {}

    /**
     * @return array{
     *     worker_id: string,
     *     worker_name: string|null,
     *     min_hours: int,
     *     scheduled_hours: int,
     *     shortfall_hours: int
     * }
     */
    public function toArray(): array
    {
        return [
            'worker_id' => $this->workerId,
            'worker_name' => $this->worker?->fullName ?? $this->workerName,
            'min_hours' => $this->minHours,
            'scheduled_hours' => $this->scheduledHours,
            'shortfall_hours' => $this->minHours - $this->scheduledHours,
        ];
    }
}
