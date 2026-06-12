<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateRosterRequest;
use App\Http\Resources\RosterResource;
use App\Models\Roster;
use App\Services\Rostering\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Exception;

final class RosterController extends Controller
{
    /**
     * Constructor.
     *
     * @param RosterService $rosterService
     * @return void
     */
    public function __construct(
        private readonly RosterService $rosterService,
    ) {}

    /**
     * Generate and persist a roster for the given month in the current year,
     * replacing any existing roster for the same period.
     *
     * @param GenerateRosterRequest $request
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function store(GenerateRosterRequest $request): JsonResponse
    {
        $roster = $this->rosterService->store(
            (int) now()->year,
            (int) $request->validated('month'),
            (int) $request->user()->id,
        );

        return $this->response(
            success: true,
            message: 'Roster generated successfully.',
            status: 201,
            data: RosterResource::make($this->rosterService->loadDetails($roster)),
        );
    }

    /**
     * List saved rosters with assignment counts.
     *
     * @return JsonResponse
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
     *
     * @param Request $request
     * @param Roster $roster
     * @return JsonResponse
     */
    public function show(Request $request, Roster $roster): JsonResponse
    {
        $date = $request->query('date');
        $shiftId = $request->query('shift_id');

        return $this->response(
            success: true,
            message: 'Roster retrieved successfully.',
            status: 200,
            data: RosterResource::make($this->rosterService->loadDetails(
                $roster,
                $date !== null ? (string) $date : null,
                $shiftId !== null ? (int) $shiftId : null,
            )),
        );
    }

    /**
     * Regenerate assignments for an existing roster.
     *
     * @param Roster $roster
     * @return JsonResponse
     *
     * @throws Exception
     */
    public function regenerate(Roster $roster): JsonResponse
    {
        $roster = $this->rosterService->regenerate($roster);

        return $this->response(
            success: true,
            message: 'Roster regenerated successfully.',
            status: 200,
            data: RosterResource::make($this->rosterService->loadDetails($roster)),
        );
    }

    /**
     * Delete a roster.
     *
     * @param Roster $roster
     * @return JsonResponse
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
}
