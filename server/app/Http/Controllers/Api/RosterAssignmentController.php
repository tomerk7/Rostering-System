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
            return $this->response(
                success: false,
                message: $exception->getMessage(),
                status: 422,
                errors: ['assignment' => [$exception->getMessage()]],
            );
        }

        $roster->refresh();

        return $this->response(
            success: true,
            message: 'Assignment created successfully.',
            status: 201,
            data: RosterResource::make($this->rosterService->loadDetails($roster)),
        );
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
            return $this->response(
                success: false,
                message: $exception->getMessage(),
                status: 422,
                errors: ['assignment' => [$exception->getMessage()]],
            );
        }

        $roster->refresh();

        return $this->response(
            success: true,
            message: 'Assignment updated successfully.',
            status: 200,
            data: RosterResource::make($this->rosterService->loadDetails($roster)),
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

        $roster->refresh();

        return $this->response(
            success: true,
            message: 'Assignment deleted successfully.',
            status: 200,
            data: RosterResource::make($this->rosterService->loadDetails($roster)),
        );
    }

}
