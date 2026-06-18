<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A shift as read from the `shifts` table.
 */
final readonly class Shift
{
    public function __construct(
        public int $id,
        public string $code,
        public string $startTime,
        public string $endTime,
        public int $durationHours,
    ) {}

    /**
     * @return array{id: int, code: string, start_time: string, end_time: string, duration_hours: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'duration_hours' => $this->durationHours,
        ];
    }
}
