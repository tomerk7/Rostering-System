<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A roster as read from the `rosters` table. `year`/`month` are derived from
 * `period_start` (first of the month). `assignmentsCount` is populated by list
 * queries (withCount); timestamps render to ISO8601 like Carbon.
 */
final readonly class Roster
{
    public function __construct(
        public int $id,
        public string $periodStart,
        public string $status,
        public ?string $generatedAt = null,
        public ?int $createdBy = null,
        public ?int $assignmentsCount = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'year' => (int) substr($this->periodStart, 0, 4),
            'month' => (int) substr($this->periodStart, 5, 2),
            'status' => $this->status,
            'generated_at' => self::iso($this->generatedAt),
            'created_by' => $this->createdBy,
            'assignments_count' => $this->assignmentsCount,
            'created_at' => self::iso($this->createdAt),
            'updated_at' => self::iso($this->updatedAt),
        ];
    }

    /**
     * Render a DB timestamp ("Y-m-d H:i:s", UTC) as ISO8601 with microseconds and
     * a Z suffix, matching Carbon::toISOString().
     */
    public static function iso(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
