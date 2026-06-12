<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\RosterStatus;
use App\Exceptions\Rostering\RosterExportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\GenerateRosterRequest;
use App\Http\Requests\RegenerateRosterRequest;
use App\Http\Resources\RosterResource;
use App\Models\Roster;
use App\Services\Rostering\Csv\RosterCsvService;
use App\Services\Rostering\RosterService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class RosterController extends Controller
{
    /**
     * Constructor.
     *
     * @return void
     */
    public function __construct(
        private readonly RosterService $rosterService,
        private readonly RosterCsvService $csvService,
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
            $request->boolean('optimize_cost'),
        );

        return $this->rosterGenerationResponse(
            $roster,
            processingStatus: 202,
            completedStatus: 201,
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
     * @param RegenerateRosterRequest $request
     * @param Roster $roster
     * @return JsonResponse
     * @throws Exception
     */
    public function regenerate(RegenerateRosterRequest $request, Roster $roster): JsonResponse
    {
        $roster = $this->rosterService->queueRegeneration($roster, $request->boolean('optimize_cost'));

        return $this->rosterGenerationResponse(
            $roster,
            processingStatus: 202,
            completedMessage: 'Roster regenerated successfully.',
        );
    }

    /**
     * Queue a roster CSV export and return the result when already finished.
     * 
     * @param Roster $roster
     * @return JsonResponse
     */
    public function export(Roster $roster): JsonResponse
    {
        try {
            $exportId = $this->csvService->queueExport($roster);
        } catch (RosterExportException $exception) {
            return $this->response(
                success: false,
                message: $exception->getMessage(),
                status: 422,
            );
        }

        $state = $this->csvService->getExportState($roster, $exportId);

        if ($state['status'] === 'completed' || $state['status'] === 'failed') {
            return $this->exportStateResponse($state);
        }

        return $this->response(
            success: true,
            message: 'Roster export queued.',
            status: 202,
            data: [
                'export_id' => $exportId,
                'status' => 'processing',
            ],
        );
    }

    /**
     * Return the status of a queued roster CSV export.
     * 
     * @param Roster $roster
     * @param string $exportId
     * @return JsonResponse
     */
    public function exportStatus(Roster $roster, string $exportId): JsonResponse
    {
        return $this->exportStateResponse($this->csvService->getExportState($roster, $exportId));
    }

    /**
     * Download a completed queued roster CSV export.
     * 
     * @param Roster $roster
     */
    public function exportDownload(Roster $roster, string $exportId): StreamedResponse
    {
        return $this->csvService->streamQueuedExport($roster, $exportId);
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

    /**
     * Return the status of a queued roster CSV export.
     *
     * @param array{
     *     status: 'not_found'|'processing'|'completed'|'failed',
     *     export_id: string,
     *     data?: array<string, mixed>,
     *     message?: string
     * } $state
     */
    private function exportStateResponse(array $state): JsonResponse
    {
        return match ($state['status']) {
            'not_found' => $this->response(
                success: false,
                message: 'Roster export not found.',
                status: 404,
            ),
            'processing' => $this->response(
                success: true,
                message: 'Roster export is processing.',
                status: 200,
                data: [
                    'export_id' => $state['export_id'],
                    'status' => 'processing',
                ],
            ),
            'completed' => $this->response(
                success: true,
                message: 'Roster export processed.',
                status: 200,
                data: $state['data'],
            ),
            'failed' => $this->response(
                success: false,
                message: 'Roster export failed.',
                status: 500,
                data: [
                    'export_id' => $state['export_id'],
                    'status' => 'failed',
                    'message' => $state['message'] ?? 'Unknown error.',
                ],
            ),
        };
    }

    /**
     * Return an API response for a roster generation request.
     *
     * @param Roster $roster
     * @param int $processingStatus
     * @param int $completedStatus
     * @param string $completedMessage
     * @return JsonResponse
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
