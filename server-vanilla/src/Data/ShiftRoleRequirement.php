<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A row of `shift_role_requirements` — how many of a given role a shift needs.
 *
 * The `role` relation defaults to null (not loaded); repositories populate it
 * only when the caller asks for it, so we don't pay for the join otherwise.
 */
final readonly class ShiftRoleRequirement
{
    public function __construct(
        public int $shiftId,
        public int $roleId,
        public int $requiredCount,
        public ?Role $role = null,
    ) {}

    /**
     * @return array{shift_id: int, role_id: int, required_count: int, role: array{id: int, code: string, name: string}|null}
     */
    public function toArray(): array
    {
        return [
            'shift_id' => $this->shiftId,
            'role_id' => $this->roleId,
            'required_count' => $this->requiredCount,
            'role' => $this->role?->toArray(),
        ];
    }
}
