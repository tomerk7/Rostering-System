<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Rostering\ManualAssignmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Resources\RosterAssignmentResource;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Services\Rostering\ManualAssignmentService;
use Illuminate\Http\JsonResponse;

final class RosterAssignmentController extends Controller
{
    /**
     * Constructor.
     *
     * @param ManualAssignmentService $manualAssignmentService
     * @return void
     */
    public function __construct(
        private readonly ManualAssignmentService $manualAssignmentService,
    ) {}

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
            $assignment = $this->manualAssignmentService->create(
                $roster,
                (int) $request->validated('worker_id'),
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

        $assignment->load(['worker.role', 'shift']);

        return $this->response(
            success: true,
            message: 'Assignment created successfully.',
            status: 201,
            data: RosterAssignmentResource::make($assignment),
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
            $this->manualAssignmentService->delete($roster, $assignment);
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
        );
    }
}
