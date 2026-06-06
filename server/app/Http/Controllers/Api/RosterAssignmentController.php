<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Rostering\ManualAssignmentException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAssignmentRequest;
use App\Http\Requests\UpdateAssignmentRequest;
use App\Http\Resources\RosterResource;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Services\Rostering\ManualAssignmentService;
use App\Services\Rostering\RosterService;
use Illuminate\Http\JsonResponse;

final class RosterAssignmentController extends Controller
{
    /**
     * Constructor.
     *
     * @param ManualAssignmentService $manualAssignmentService
     * @param RosterService $rosterService
     * @return void
     */
    public function __construct(
        private readonly ManualAssignmentService $manualAssignmentService,
        private readonly RosterService $rosterService,
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
            $this->manualAssignmentService->create(
                $roster,
                (int) $request->validated('worker_id'),
                (int) $request->validated('shift_id'),
                (string) $request->validated('work_date'),
            );
        } catch (ManualAssignmentException $exception) {
            return $this->assignmentError($exception);
        }

        return $this->rosterResponse($roster, 'Assignment created successfully.', 201);
    }

    /**
     * Reassign an existing assignment to a different worker on a draft roster.
     *
     * @param UpdateAssignmentRequest $request
     * @param Roster $roster
     * @param RosterAssignment $assignment
     * @return JsonResponse
     */
    public function update(UpdateAssignmentRequest $request, Roster $roster, RosterAssignment $assignment): JsonResponse
    {
        try {
            $this->manualAssignmentService->change(
                $roster,
                $assignment,
                (int) $request->validated('worker_id'),
            );
        } catch (ManualAssignmentException $exception) {
            return $this->assignmentError($exception);
        }

        return $this->rosterResponse($roster, 'Assignment updated successfully.', 200);
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
            return $this->assignmentError($exception);
        }

        return $this->rosterResponse($roster, 'Assignment deleted successfully.', 200);
    }

    /**
     * Build the success response with the refreshed roster detail.
     *
     * @param Roster $roster
     * @param string $message
     * @param int $status
     * @return JsonResponse
     */
    private function rosterResponse(Roster $roster, string $message, int $status): JsonResponse
    {
        $roster->refresh();

        return $this->response(
            success: true,
            message: $message,
            status: $status,
            data: RosterResource::make($this->rosterService->loadDetails($roster)),
        );
    }

    /**
     * Build the validation error response for a failed manual assignment.
     *
     * @param ManualAssignmentException $exception
     * @return JsonResponse
     */
    private function assignmentError(ManualAssignmentException $exception): JsonResponse
    {
        return $this->response(
            success: false,
            message: $exception->getMessage(),
            status: 422,
            errors: ['assignment' => [$exception->getMessage()]],
        );
    }
}
