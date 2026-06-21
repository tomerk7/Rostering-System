<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Data\Roster;
use App\Data\User;
use App\Enums\RosterStatus;
use App\Exceptions\AssignmentRangeException;
use App\Exceptions\BenchmarkException;
use App\Exceptions\ManualAssignmentException;
use App\Exceptions\RosterExportException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\JsonResponse;
use App\Http\RawResponse;
use App\Http\Request;
use App\Services\Rostering\Csv\RosterCsvService;
use App\Services\Rostering\Data\DistributionPreference;
use App\Services\Rostering\RosterBenchmark;
use App\Services\RosterAssignmentService;
use App\Services\RosterReportService;
use App\Services\RosterService;
use App\Validation\Validator;
use DateMalformedStringException;
use Exception;
use Random\RandomException;
use Throwable;

final class RosterController
{
    use ApiResponse;

    /**
     * Class constructor.
     *
     * @param RosterService $rosterService
     * @param RosterAssignmentService $assignmentService
     * @param RosterReportService $reportService
     * @param RosterBenchmark $benchmark
     * @param RosterCsvService $csvService
     */
    public function __construct(
        private readonly RosterService $rosterService = new RosterService,
        private readonly RosterAssignmentService $assignmentService = new RosterAssignmentService,
        private readonly RosterReportService $reportService = new RosterReportService,
        private readonly RosterBenchmark $benchmark = new RosterBenchmark,
        private readonly RosterCsvService $csvService = new RosterCsvService,
    ) {}

    /**
     * GET /api/rosters — list rosters (newest first) with assignment counts.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'Rosters retrieved successfully.',
            data: $this->rosterService->list(),
            meta: ['current_year' => (int) gmdate('Y')],
        );
    }

    /**
     * POST /api/rosters — queue generation of a roster for the given month in the
     * current year. Generation runs asynchronously on the worker daemon; this
     * returns 202 with the processing roster (the client polls GET show).
     *
     * @param Request $request
     * @return JsonResponse
     * @throws RandomException
     */
    public function store(Request $request): JsonResponse
    {
        $data = (new Validator($request->all()))->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'optimize_cost' => ['boolean'],
            'distribution_preference' => ['in:' . implode(',', DistributionPreference::values())],
        ]);

        [$optimizeCost, $preference] = $this->optimization($request, $data);

        /** @var User $user */
        $user = $request->attributes['user'];

        $roster = $this->rosterService->queueStore(
            (int) gmdate('Y'),
            (int) $data['month'],
            $user->id,
            $optimizeCost,
            $preference,
        );

        return $this->generationResponse($roster, processingStatus: 202, completedStatus: 201);
    }

    /**
     * POST /api/rosters/{roster}/regenerate — queue regeneration of an existing
     * roster's assignments (async on the worker daemon). Returns 202 with the
     * processing roster.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse
     * @throws RandomException
     */
    public function regenerate(Request $request, array $params): JsonResponse
    {
        // Route-binding parity: a missing roster is a 404 before validation runs.
        $roster = $this->rosterService->find((int) $params['roster']);

        if ($roster === null) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        $data = (new Validator($request->all()))->validate([
            'optimize_cost' => ['boolean'],
            'distribution_preference' => ['in:' . implode(',', DistributionPreference::values())],
        ]);

        [$optimizeCost, $preference] = $this->optimization($request, $data);

        $roster = $this->rosterService->queueRegeneration($roster, $optimizeCost, $preference);

        return $this->generationResponse(
            $roster,
            processingStatus: 202,
            completedMessage: 'Roster regenerated successfully.',
        );
    }

    /**
     * POST /api/rosters/benchmark — run a plain vs cost-optimized generation
     * benchmark for the given month in the current year. Both runs are previews
     * only — nothing is saved.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws Exception
     */
    public function benchmark(Request $request): JsonResponse
    {
        $data = (new Validator($request->all()))->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'distribution_preference' => ['required', 'in:' . implode(',', DistributionPreference::values())],
        ]);

        $preference = DistributionPreference::from((string) $data['distribution_preference']);

        try {
            $result = $this->benchmark->run(
                (int) gmdate('Y'),
                (int) $data['month'],
                $preference,
            );
        } catch (BenchmarkException $e) {
            return $this->response(success: false, message: $e->getMessage(), status: 422);
        }

        return $this->response(
            success: true,
            message: 'Benchmark completed successfully.',
            data: $result->toArray(),
        );
    }

    /**
     * GET /api/rosters/{roster}/assignments — assignments in a date range, with
     * the roster's monthly assigned hours by worker in the meta.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse
     * @throws DateMalformedStringException
     */
    public function assignments(Request $request, array $params): JsonResponse
    {
        // Route-binding parity: a missing roster is a 404 before validation runs.
        $roster = $this->rosterService->find((int) $params['roster']);

        if ($roster === null) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        $data = (new Validator([
            'from_date' => $request->query('from_date'),
            'to_date' => $request->query('to_date'),
        ]))->validate([
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
        ]);

        try {
            $result = $this->assignmentService->listForRange($roster, $data['from_date'], $data['to_date']);
        } catch (AssignmentRangeException $e) {
            return $this->response(
                success: false,
                message: $e->getMessage(),
                status: 422,
                errors: ['date_range' => [$e->getMessage()]],
            );
        }

        return $this->response(
            success: true,
            message: 'Assignments retrieved successfully.',
            data: $result['assignments'],
            meta: [
                'from_date' => $result['from_date'],
                'to_date' => $result['to_date'],
                'assigned_hours_by_worker' => $result['assigned_hours_by_worker'],
            ],
        );
    }

    /**
     * POST /api/rosters/{roster}/assignments — add a manual assignment, returning
     * the refreshed roster detail (filtered to the new assignment's date).
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse
     * @throws Throwable
     */
    public function storeAssignment(Request $request, array $params): JsonResponse
    {
        $roster = $this->rosterService->find((int) $params['roster']);

        if (!$roster) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        $data = (new Validator($request->all()))->validate([
            'worker_id' => ['required', 'string', 'size:9', 'exists:workers,israeli_id'],
            'shift_id' => ['required', 'integer', 'exists:shifts,id'],
            'work_date' => ['required', 'date'],
        ]);

        try {
            $result = $this->assignmentService->create(
                $roster,
                (string) $data['worker_id'],
                (int) $data['shift_id'],
                (string) $data['work_date'],
            );
        } catch (ManualAssignmentException $e) {
            return $this->assignmentError($e);
        }

        return $this->response(
            success: true,
            message: 'Assignment created successfully.',
            status: 201,
            data: $result,
        );
    }

    /**
     * DELETE /api/rosters/{roster}/assignments/{assignment} — remove an
     * assignment, returning the refreshed roster detail.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse
     * @throws Throwable
     */
    public function destroyAssignment(Request $request, array $params): JsonResponse
    {
        $roster = $this->rosterService->find((int) $params['roster']);

        if (!$roster) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        $assignment = $this->assignmentService->findAssignment((int) $params['assignment']);

        if (!$assignment || $assignment->rosterId !== $roster->id) {
            return $this->response(success: false, message: 'Resource not found.', status: 404);
        }

        try {
            $result = $this->assignmentService->delete($roster, $assignment);
        } catch (ManualAssignmentException $e) {
            return $this->assignmentError($e);
        }

        return $this->response(
            success: true,
            message: 'Assignment deleted successfully.',
            data: $result,
        );
    }

    /**
     * Render a manual-assignment constraint violation as a 422 in the standard
     * response, with the message under the `assignment` error key.
     *
     * @param ManualAssignmentException $e
     * @return JsonResponse
     */
    private function assignmentError(ManualAssignmentException $e): JsonResponse
    {
        return $this->response(
            success: false,
            message: $e->getMessage(),
            status: 422,
            errors: ['assignment' => [$e->getMessage()]],
        );
    }

    /**
     * Resolve the cost-optimization flag and chosen distribution preference from a
     * generation request. A preference implies optimization and is carried through
     * to the job, which derives the optimizer penalties from live data at run
     * time; otherwise the raw optimize_cost flag is used with no preference.
     *
     * @param Request $request
     * @param array<string, mixed> $validated
     * @return array{0: bool, 1: DistributionPreference|null}
     */
    private function optimization(Request $request, array $validated): array
    {
        $preference = $validated['distribution_preference'] ?? null;

        if ($preference !== null && $preference !== '') {
            return [true, DistributionPreference::from((string) $preference)];
        }

        return [filter_var($request->input('optimize_cost'), FILTER_VALIDATE_BOOLEAN), null];
    }

    /**
     * Build the API response for a roster generation request, branching on the
     * roster's lifecycle status. Generation is async, so in practice this returns
     * the processing branch; the ready/failed branches mirror
     * rosterGenerationResponse() for completeness.
     *
     * @param Roster $roster
     * @param int $processingStatus
     * @param int $completedStatus
     * @param string $completedMessage
     * @return JsonResponse
     */
    private function generationResponse(
        Roster $roster,
        int $processingStatus = 200,
        int $completedStatus = 200,
        string $completedMessage = 'Roster generated successfully.',
    ): JsonResponse {
        return match ($roster->status) {
            RosterStatus::Processing->value => $this->response(
                success: true,
                message: 'Roster generation is processing.',
                status: $processingStatus,
                data: $this->bareRoster($roster),
            ),
            RosterStatus::Failed->value => $this->response(
                success: false,
                message: 'Roster generation failed.',
                status: 500,
                data: $this->bareRoster($roster),
            ),
            RosterStatus::Ready->value => $this->response(
                success: true,
                message: $completedMessage,
                status: $completedStatus,
                data: $this->rosterService->loadDetails($this->rosterService->find($roster->id) ?? $roster),
            ),
            default => $this->response(
                success: true,
                message: 'Roster generation is processing.',
                status: $processingStatus,
                data: $this->bareRoster($roster),
            ),
        };
    }

    /**
     * The RosterResource shape for a roster with no relations / assignment count
     *
     * @param Roster $roster
     * @return array<string, mixed>
     */
    private function bareRoster(Roster $roster): array
    {
        return [
            'id' => $roster->id,
            'year' => (int) substr($roster->periodStart, 0, 4),
            'month' => (int) substr($roster->periodStart, 5, 2),
            'status' => $roster->status,
            'generated_at' => Roster::iso($roster->generatedAt),
            'created_by' => $roster->createdBy,
            'created_at' => Roster::iso($roster->createdAt),
            'updated_at' => Roster::iso($roster->updatedAt),
        ];
    }

    /**
     * GET /api/rosters/{roster}/stats — per-worker statistics and a roster-level
     * summary (totals + leaderboards) for a saved roster.
     *
     * @param Request $request
     * @param  array<string, string>  $params
     * @return JsonResponse
     */
    public function stats(Request $request, array $params): JsonResponse
    {
        $roster = $this->rosterService->find((int) $params['roster']);

        if ($roster === null) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        return $this->response(
            success: true,
            message: 'Roster stats retrieved successfully.',
            data: $this->reportService->forRoster($roster),
        );
    }

    /**
     * GET /api/rosters/{roster} — one roster with enriched assignments, reports,
     * and summary. Query params: `date` and `shift_id` filter the listed
     * assignments; `include_assignments` (default true) omits them entirely.
     *
     * @param Request $request
     * @param  array<string, string>  $params
     * @return JsonResponse
     */
    public function show(Request $request, array $params): JsonResponse
    {
        $roster = $this->rosterService->find((int) $params['roster']);

        if ($roster === null) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        $date = $request->query('date');
        $shiftId = $request->query('shift_id');
        // boolean cast: absent -> default true; present -> filter_var.
        $includeAssignments = $request->hasQuery('include_assignments')
            ? filter_var($request->query('include_assignments', ''), FILTER_VALIDATE_BOOLEAN)
            : true;

        return $this->response(
            success: true,
            message: 'Roster retrieved successfully.',
            data: $this->rosterService->loadDetails(
                $roster,
                $date !== null ? (string) $date : null,
                $shiftId !== null ? (int) $shiftId : null,
                $includeAssignments,
            ),
        );
    }

    /**
     * DELETE /api/rosters/{roster} — delete a roster (cascades to its
     * assignments, reports, and queued jobs).
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse
     */
    public function destroy(Request $request, array $params): JsonResponse
    {
        $roster = $this->rosterService->find((int) $params['roster']);

        if ($roster === null) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        $this->rosterService->delete($roster->id);

        return $this->response(success: true, message: 'Roster deleted successfully.');
    }

    /**
     * POST /api/rosters/{roster}/export — queue a roster CSV export for the worker
     * daemon, returning the result immediately if it is already finished.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse
     * @throws RandomException
     */
    public function export(Request $request, array $params): JsonResponse
    {
        $roster = $this->rosterService->find((int) $params['roster']);

        if ($roster === null) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        try {
            $exportId = $this->csvService->queueExport($roster->id);
        } catch (RosterExportException $e) {
            return $this->response(success: false, message: $e->getMessage(), status: 422);
        }

        $state = $this->csvService->getExportState($roster->id, $exportId);

        if ($state['status'] === 'completed' || $state['status'] === 'failed') {
            return $this->exportStateResponse($state);
        }

        return $this->response(
            success: true,
            message: 'Roster export queued.',
            status: 202,
            data: ['export_id' => $exportId, 'status' => 'processing'],
        );
    }

    /**
     * GET /api/rosters/{roster}/export/{export} — poll a queued export's status.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse
     */
    public function exportStatus(Request $request, array $params): JsonResponse
    {
        $roster = $this->rosterService->find((int) $params['roster']);

        if ($roster === null) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        return $this->exportStateResponse($this->csvService->getExportState($roster->id, $params['export']));
    }

    /**
     * GET /api/rosters/{roster}/export/{export}/download — stream a completed
     * export (read-then-forget).
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse|RawResponse
     */
    public function exportDownload(Request $request, array $params): JsonResponse|RawResponse
    {
        $roster = $this->rosterService->find((int) $params['roster']);

        if ($roster === null) {
            return $this->response(success: false, message: 'Roster not found.', status: 404);
        }

        $export = $this->csvService->takeExport($roster->id, $params['export']);

        if ($export === null) {
            return $this->response(success: false, message: 'Roster export not found or not ready.', status: 404);
        }

        return new RawResponse(
            $export['content'],
            'text/csv',
            200,
            ['Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"'],
        );
    }

    /**
     * Map a roster export state to its HTTP response.
     *
     * @param array<string, mixed> $state
     * @return JsonResponse
     */
    private function exportStateResponse(array $state): JsonResponse
    {
        return match ($state['status']) {
            'not_found' => $this->response(success: false, message: 'Roster export not found.', status: 404),
            'processing' => $this->response(
                success: true,
                message: 'Roster export is processing.',
                status: 200,
                data: ['export_id' => $state['export_id'], 'status' => 'processing'],
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
                data: ['export_id' => $state['export_id'], 'status' => 'failed', 'message' => $state['message'] ?? 'Unknown error.'],
            ),
        };
    }
}
