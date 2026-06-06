<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\Rostering\RosterStatusException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateRosterRequest;
use App\Http\Resources\RosterResource;
use App\Models\Roster;
use App\Models\RosterGeneration;
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
     * Queue an asynchronous roster generation and return its tracking id.
     *
     * @param GenerateRosterRequest $request
     * @return JsonResponse
     */
    public function generate(GenerateRosterRequest $request): JsonResponse
    {
        $generation = $this->rosterService->queueGeneration(
            (int) $request->validated('year'),
            (int) $request->validated('month'),
            (int) $request->user()->id,
        );

        return $this->response(
            success: true,
            message: 'Roster generation queued.',
            status: 202,
            data: [
                'generation_id' => $generation->uuid,
                'status' => $generation->status,
            ],
        );
    }

    /**
     * Return the current state of a queued roster generation.
     *
     * @param Request $request
     * @param RosterGeneration $generation
     * @return JsonResponse
     */
    public function showGeneration(Request $request, RosterGeneration $generation): JsonResponse
    {
        return match ($generation->status) {
            'queued', 'processing' => $this->response(
                success: true,
                message: 'Roster generation is processing.',
                status: 200,
                data: [
                    'generation_id' => $generation->uuid,
                    'status' => $generation->status,
                ],
            ),
            'completed' => $this->response(
                success: true,
                message: 'Roster generation completed.',
                status: 200,
                data: [
                    'generation_id' => $generation->uuid,
                    'status' => $generation->status,
                    'roster' => (function () use ($request, $generation): array {
                        $roster = $generation->roster()->firstOrFail();
                        $data = RosterResource::make($this->rosterService->loadDetails($roster))
                            ->resolve($request);

                        $generation->delete();

                        return $data;
                    })(),
                ],
            ),
            default => $this->response(
                success: false,
                message: 'Unknown generation status.',
                status: 200,
                data: [
                    'generation_id' => $generation->uuid,
                    'status' => $generation->status,
                ],
            ),
        };
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
                $date ?? (string) $date,
                $shiftId ?? (int) $shiftId,
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
