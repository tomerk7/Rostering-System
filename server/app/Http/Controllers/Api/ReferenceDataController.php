<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftRoleRequirement;
use Illuminate\Http\JsonResponse;

final class ReferenceDataController extends Controller
{
    /**
     * Return reference values needed by worker forms.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'Reference data retrieved successfully.',
            status: 200,
            data: [
                'roles' => Role::query()
                    ->orderBy('name')
                    ->get(['id', 'code', 'name']),
                'shifts' => Shift::query()
                    ->orderBy('code')
                    ->get(['id', 'code', 'label', 'start_time', 'end_time', 'duration_hours']),
                'shift_role_requirements' => ShiftRoleRequirement::query()
                    ->with(['role:id,code,name'])
                    ->orderBy('shift_id')
                    ->orderBy('role_id')
                    ->get(['shift_id', 'role_id', 'required_count'])
                    ->map(static fn (ShiftRoleRequirement $requirement): array => [
                        'shift_id' => $requirement->shift_id,
                        'role_id' => $requirement->role_id,
                        'required_count' => $requirement->required_count,
                        'role' => $requirement->role === null ? null : [
                            'id' => $requirement->role->id,
                            'code' => $requirement->role->code,
                            'name' => $requirement->role->name,
                        ],
                    ])
                    ->values(),
            ],
        );
    }
}
