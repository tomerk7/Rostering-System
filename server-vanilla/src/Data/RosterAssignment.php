<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A roster assignment as read from the `roster_assignments` table. `workDate` is
 * a plain "Y-m-d" date string (matching date cast → toDateString).
 *
 * The `worker` (with its `role`) and `shift` relations default to null (not
 * loaded); list reads hydrate them for the API shape.
 */
final readonly class RosterAssignment
{
    /**
     * Class constructor.
     *
     * @param int $id
     * @param int $rosterId
     * @param string $workerId
     * @param int $shiftId
     * @param string $workDate
     * @param string $source
     * @param string|null $hourlyCost
     * @param Worker|null $worker
     * @param Shift|null $shift
     */
    public function __construct(
        public int $id,
        public int $rosterId,
        public string $workerId,
        public int $shiftId,
        public string $workDate,
        public string $source,
        public ?string $hourlyCost = null,
        public ?Worker $worker = null,
        public ?Shift $shift = null,
    ) {}

    /**
     * The API shape, for the roster assignment API.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'worker_id' => $this->workerId,
            'worker_name' => $this->worker?->fullName,
            'shift_id' => $this->shiftId,
            'shift_code' => $this->shift?->code,
            'role_id' => $this->worker?->role?->id,
            'role_name' => $this->worker?->role?->name,
            'work_date' => $this->workDate,
            'source' => $this->source,
        ];
    }
}
