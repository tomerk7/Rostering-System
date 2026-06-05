<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\Shift;
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
            ],
        );
    }
}
