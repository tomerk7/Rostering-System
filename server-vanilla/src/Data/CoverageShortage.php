<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A coverage shortage (understaffed slot) as read from the `coverage_shortages`
 * table. `shift` and `role` relations default to null (not loaded). `toArray()`
 * is the report-row shape the report loader emits.
 */
final readonly class CoverageShortage
{
    /**
     * Class constructor.
     *
     * @param int $id
     * @param int $rosterId
     * @param string $workDate
     * @param int $shiftId
     * @param int $roleId
     * @param int $requiredCount
     * @param int $assignedCount
     * @param Shift|null $shift
     * @param Role|null $role
     */
    public function __construct(
        public int $id,
        public int $rosterId,
        public string $workDate,
        public int $shiftId,
        public int $roleId,
        public int $requiredCount,
        public int $assignedCount,
        public ?Shift $shift = null,
        public ?Role $role = null,
    ) {}

    /**
     * @return array{
     *     work_date: string,
     *     shift_id: int,
     *     shift_code: string|null,
     *     role_id: int,
     *     role_name: string|null,
     *     required: int,
     *     assigned: int,
     *     missing: int
     * }
     */
    public function toArray(): array
    {
        return [
            'work_date' => $this->workDate,
            'shift_id' => $this->shiftId,
            'shift_code' => $this->shift?->code,
            'role_id' => $this->roleId,
            'role_name' => $this->role?->name,
            'required' => $this->requiredCount,
            'assigned' => $this->assignedCount,
            'missing' => $this->requiredCount - $this->assignedCount,
        ];
    }
}
