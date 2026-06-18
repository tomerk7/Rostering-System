<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Role;
use App\Data\Shift;
use App\Data\ShiftRoleRequirement;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\Request;
use App\Repositories\RoleRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\ShiftRoleRequirementRepository;

final class WorkerController
{
    use ApiResponse;

    /**
     * Class constructor.
     *
     * @param RoleRepository $roles
     * @param ShiftRepository $shifts
     * @param ShiftRoleRequirementRepository $requirements
     */
    public function __construct(
        private readonly RoleRepository $roles = new RoleRepository,
        private readonly ShiftRepository $shifts = new ShiftRepository,
        private readonly ShiftRoleRequirementRepository $requirements = new ShiftRoleRequirementRepository,
    ) {}

    /**
     * GET /api/workers/reference-data — roles, shifts, and staffing demand for
     * worker forms.
     *
     * @return array{success: bool, message: string, data: mixed, errors: array<string, mixed>, meta: array<string, mixed>}
     */
    public function referenceData(Request $request): array
    {
        return $this->response(
            success: true,
            message: 'Reference data retrieved successfully.',
            data: [
                'roles' => array_map(static fn (Role $role): array => $role->toArray(), $this->roles->all()),
                'shifts' => array_map(static fn (Shift $shift): array => $shift->toArray(), $this->shifts->all()),
                'shift_role_requirements' => array_map(
                    static fn (ShiftRoleRequirement $requirement): array => $requirement->toArray(),
                    $this->requirements->all(withRole: true),
                ),
            ],
        );
    }
}
