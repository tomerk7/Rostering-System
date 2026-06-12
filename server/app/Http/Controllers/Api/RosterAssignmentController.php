<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Rostering\AssignmentRangeException;
use App\Exceptions\Rostering\ManualAssignmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Resources\RosterAssignmentResource;
use App\Http\Resources\RosterResource;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Services\Rostering\RosterAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RosterAssignmentController extends Controller
{
    /**
     * Constructor.
     *
     * @param RosterAssignmentService $rosterAssignmentService
     */
    public function __construct(
        private readonly RosterAssignmentService $rosterAssignmentService,
    ) {}

    /**
     * List assignments in a date range with monthly assigned hours by worker.
     *
     * @param Request $request
     * @param Roster $roster
     * @return JsonResponse
     */
    public function index(Request $request, Roster $roster): JsonResponse
    {
        $validated = $request->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        try {
            $result = $this->rosterAssignmentService->listForRange(
                $roster,
                $validated['from_date'],
                $validated['to_date'],
            );
        } catch (AssignmentRangeException $exception) {
            return $this->response(
                success: false,
                message: $exception->getMessage(),
                status: 422,
                errors: ['date_range' => [$exception->getMessage()]],
            );
        }

        return $this->response(
            success: true,
            message: 'Assignments retrieved successfully.',
            status: 200,
            data: RosterAssignmentResource::collection($result['assignments']),
            meta: [
                'from_date' => $result['from_date'],
                'to_date' => $result['to_date'],
                'assigned_hours_by_worker' => $result['assigned_hours_by_worker'],
            ],
        );
    }

    /**
     * Add a manual assignment to a draft roster.
     * 
     * @param StoreAssignmentRequest $request
     * @param Roster $roster
     * @return JsonResponse
     */
    public function store(StoreAssignmentRequest $request, Roster $roster): JsonResponse
    {
        try {
            $roster = $this->rosterAssignmentService->create(
                $roster,
                (string) $request->validated('worker_id'),
                (int) $request->validated('shift_id'),
                (string) $request->validated('work_date'),
            );
        } catch (ManualAssignmentException $exception) {
            return $this->response(
                success: false,
                message: $exception->getMessage(),
                status: 422,
                errors: ['assignment' => [$exception->getMessage()]],
            );
        }

        return $this->response(
            success: true,
            message: 'Assignment created successfully.',
            status: 201,
            data: RosterResource::make($roster),
        );
    }

    /**
     * Remove an assignment from a draft roster.
     * 
     * @param Roster $roster
     * @param RosterAssignment $assignment
     * @return JsonResponse
     */
    public function destroy(Roster $roster, RosterAssignment $assignment): JsonResponse
    {
        try {
            $roster = $this->rosterAssignmentService->delete($roster, $assignment);
        } catch (ManualAssignmentException $exception) {
            return $this->response(
                success: false,
                message: $exception->getMessage(),
                status: 422,
                errors: ['assignment' => [$exception->getMessage()]],
            );
        }

        return $this->response(
            success: true,
            message: 'Assignment deleted successfully.',
            status: 200,
            data: RosterResource::make($roster),
        );
    }
}
