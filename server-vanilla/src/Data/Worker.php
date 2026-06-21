<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A worker as read from the `workers` table. `israeliId` is the natural PK.
 * Timestamps are stored raw (DB "Y-m-d H:i:s", UTC) and rendered to ISO8601 by
 * toArray, matching Carbon `toISOString`.
 *
 * `role` and `contract` relations default to null (not loaded).
 */
final readonly class Worker
{
    /**
     * Class constructor.
     * 
     * @param string $israeliId
     * @param string $fullName
     * @param bool $isActive
     * @param string|null $deletedAt
     * @param string|null $createdAt
     * @param string|null $updatedAt
     * @param Role|null $role
     * @param Contract|null $contract
     */
    public function __construct(
        public string $israeliId,
        public string $fullName,
        public bool $isActive,
        public ?string $deletedAt = null,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
        public ?Role $role = null,
        public ?Contract $contract = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'full_name' => $this->fullName,
            'israeli_id' => $this->israeliId,
            'is_active' => $this->isActive,
            'deleted_at' => self::iso($this->deletedAt),
            'is_trashed' => $this->deletedAt !== null,
            'role' => [
                'id' => $this->role?->id,
                'code' => $this->role?->code,
                'name' => $this->role?->name,
            ],
            'contract' => $this->contract?->toArray(),
            'created_at' => self::iso($this->createdAt),
            'updated_at' => self::iso($this->updatedAt),
        ];
    }

    /**
     * Render a DB timestamp ("Y-m-d H:i:s", UTC) as ISO8601 with microseconds and
     * a Z suffix, matching Carbon::toISOString() (e.g. "2026-06-18T06:05:32.000000Z").
     */
    private static function iso(?string $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        return (new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.u\Z');
    }
}
