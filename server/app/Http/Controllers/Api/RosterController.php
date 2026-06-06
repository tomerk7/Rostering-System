<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Rostering\RosterStatusException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PreviewRosterRequest;
use App\Http\Resources\RosterResource;
use App\Models\Roster;
use App\Services\Rostering\RosterPreviewService;
use App\Services\Rostering\RosterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Exception;

final class RosterController extends Controller
{
    /**
     * Constructor.
     *
     * @param RosterPreviewService $previewService
     * @param RosterService $rosterService
     * @return void
     */
    public function __construct(
        private readonly RosterPreviewService $previewService,
        private readonly RosterService $rosterService,
    ) {}

    /**
     * Generate a roster preview without persisting it.
     * 
     * @param PreviewRosterRequest $request
     * @return JsonResponse
     * @throws Exception
     */
    public function preview(PreviewRosterRequest $request): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'Roster preview generated successfully.',
            status: 200,
            data: $this->previewService->generate(
                (int) $request->validated('year'),
                (int) $request->validated('month'),
            ),
        );
    }

    /**
     * Regenerate and persist a draft roster for the requested period.
     *  
     * @param PreviewRosterRequest $request
     * @return JsonResponse
     * @throws Exception
     * @throws ValidationException
     */
    public function store(PreviewRosterRequest $request): JsonResponse
    {
        $roster = $this->rosterService->saveDraft(
            (int) $request->validated('year'),
            (int) $request->validated('month'),
            (int) $request->user()->id,
        );

        if ($request->boolean('publish')) {
            $roster = $this->rosterService->publish($roster);
        }

        return $this->response(
            success: true,
            message: $request->boolean('publish')
                ? 'Roster published successfully.'
                : 'Roster saved as draft successfully.',
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
                $date === null ? null : (string) $date,
                $shiftId === null ? null : (int) $shiftId,
            )),
        );
    }

    /**
     * Publish a draft roster for its month.
     *  
     * @param Roster $roster
     * @return JsonResponse
     * @throws Exception
     * @throws RosterStatusException
     */
    public function publish(Roster $roster): JsonResponse
    {
        try {
            $roster = $this->rosterService->publish($roster);
        } catch (RosterStatusException $exception) {
            return $this->response(
                success: false,
                message: $exception->getMessage(),
                status: 422,
                errors: ['status' => [$exception->getMessage()]],
            );
        }

        return $this->response(
            success: true,
            message: 'Roster published successfully.',
            status: 200,
            data: RosterResource::make($roster),
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
