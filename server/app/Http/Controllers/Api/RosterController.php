<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RosterStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateRosterRequest;
use App\Http\Resources\RosterResource;
use App\Models\Roster;
use App\Services\Rostering\RosterService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RosterController extends Controller
{
    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct(
        private readonly RosterService $rosterService,
    ) {}

    /**
     * Queue generation of a roster for the given month in the current year.
     *
     *
     * @throws Exception
     */
    public function store(GenerateRosterRequest $request): JsonResponse
    {
        $roster = $this->rosterService->queueStore(
            (int) now()->year,
            (int) $request->validated('month'),
            (int) $request->user()->id,
        );

        return $this->rosterGenerationResponse(
            $roster,
            processingStatus: 202,
            completedStatus: 201,
        );
    }

    /**
     * List saved rosters with assignment counts.
     */
    public function index(): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'Rosters retrieved successfully.',
            status: 200,
            data: RosterResource::collection($this->rosterService->list()),
            meta: [
                'current_year' => (int) now()->year,
            ],
        );
    }

    /**
     * Show one roster with enriched assignments, optionally filtered by date or shift.
     */
    public function show(Request $request, Roster $roster): JsonResponse
    {
        $date = $request->query('date');
        $shiftId = $request->query('shift_id');
        $includeAssignments = $request->boolean('include_assignments', true);

        return $this->response(
            success: true,
            message: 'Roster retrieved successfully.',
            status: 200,
            data: RosterResource::make($this->rosterService->loadDetails(
                $roster,
                $date !== null ? (string) $date : null,
                $shiftId !== null ? (int) $shiftId : null,
                $includeAssignments,
            )),
        );
    }

    /**
     * Queue regeneration of assignments for an existing roster.
     *
     *
     * @throws Exception
     */
    public function regenerate(Roster $roster): JsonResponse
    {
        $roster = $this->rosterService->queueRegeneration($roster);

        return $this->rosterGenerationResponse(
            $roster,
            processingStatus: 202,
            completedMessage: 'Roster regenerated successfully.',
        );
    }

    /**
     * Delete a roster.
     */
    public function destroy(Roster $roster): JsonResponse
    {
        $this->rosterService->delete($roster);

        return $this->response(
            success: true,
            message: 'Roster deleted successfully.',
            status: 200,
        );
    }

    /**
     * Return an API response for a roster generation request.
     */
    private function rosterGenerationResponse(
        Roster $roster,
        int $processingStatus = 200,
        int $completedStatus = 200,
        string $completedMessage = 'Roster generated successfully.',
    ): JsonResponse {
        return match ($roster->status) {
            RosterStatus::Processing => $this->response(
                success: true,
                message: 'Roster generation is processing.',
                status: $processingStatus,
                data: RosterResource::make($roster),
            ),
            RosterStatus::Failed => $this->response(
                success: false,
                message: 'Roster generation failed.',
                status: 500,
                data: RosterResource::make($roster),
            ),
            RosterStatus::Ready => $this->response(
                success: true,
                message: $completedMessage,
                status: $completedStatus,
                data: RosterResource::make($this->rosterService->loadDetails($roster->fresh())),
            ),
        };
    }
}
