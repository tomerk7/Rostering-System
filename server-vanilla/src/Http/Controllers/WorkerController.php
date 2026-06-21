<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exceptions\WorkerContractException;
use App\Http\Controllers\Concerns\ApiResponse;
use App\Http\JsonResponse;
use App\Http\RawResponse;
use App\Http\Request;
use App\Services\Workers\Csv\WorkerCsvService;
use App\Services\WorkerService;
use App\Validation\ValidationException;
use App\Validation\Validator;

final class WorkerController
{
    use ApiResponse;

    /**
     * Class constructor.
     *
     * @param WorkerService $workerService
     * @param WorkerCsvService $csv
     */
    public function __construct(
        private readonly WorkerService $workerService = new WorkerService,
        private readonly WorkerCsvService $csv = new WorkerCsvService,
    ) {}

    /**
     * GET /api/workers/reference-data — roles, shifts, and staffing demand for
     * worker forms.
     */
    public function referenceData(Request $request): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'Reference data retrieved successfully.',
            data: $this->workerService->referenceData(),
        );
    }

    /**
     * GET /api/workers — filtered, paginated list of workers.
     */
    public function index(Request $request): JsonResponse
    {
        $result = $this->workerService->list($request);

        return $this->response(
            success: true,
            message: 'Workers retrieved successfully.',
            data: $result['data'],
            meta: $result['meta'],
        );
    }

    /**
     * GET /api/workers/{worker} — a single worker by israeli id.
     *
     * @param  array<string, string>  $params
     * @return JsonResponse
     */
    public function show(Request $request, array $params): JsonResponse
    {
        $worker = $this->workerService->find($params['worker']);

        if ($worker === null) {
            return $this->response(success: false, message: 'Worker not found.', status: 404);
        }

        return $this->response(
            success: true,
            message: 'Worker retrieved successfully.',
            data: $worker,
        );
    }

    /**
     * POST /api/workers — create a worker with contract and availability.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function store(Request $request): JsonResponse
    {
        $data = (new Validator($request->all()))->validate(self::storeRules());

        try {
            $worker = $this->workerService->create($data);
        } catch (WorkerContractException $e) {
            return $this->contractError($e);
        }

        return $this->response(
            success: true,
            message: 'Worker created successfully.',
            status: 201,
            data: $worker,
        );
    }

    /**
     * PUT/PATCH /api/workers/{worker} — update a worker.
     *
     * @param Request $request
     * @param  array<string, string>  $params
     * @return JsonResponse
     */
    public function update(Request $request, array $params): JsonResponse
    {
        $id = $params['worker'];

        // Route-binding parity: missing worker is a 404 before validation runs.
        if ($this->workerService->find($id) === null) {
            return $this->response(success: false, message: 'Worker not found.', status: 404);
        }

        $data = (new Validator($request->all()))->validate(self::updateRules());

        try {
            $worker = $this->workerService->update($id, $data);
        } catch (WorkerContractException $e) {
            return $this->contractError($e);
        }

        return $this->response(
            success: true,
            message: 'Worker updated successfully.',
            data: $worker,
        );
    }

    /**
     * DELETE /api/workers/{worker} — soft-delete a worker.
     *
     * @param Request $request
     * @param  array<string, string>  $params
     * @return JsonResponse
     */
    public function destroy(Request $request, array $params): JsonResponse
    {
        $id = $params['worker'];

        if ($this->workerService->find($id) === null) {
            return $this->response(success: false, message: 'Worker not found.', status: 404);
        }

        $this->workerService->softDelete($id);

        return $this->response(
            success: true,
            message: 'Worker deleted successfully.',
        );
    }

    /**
     * POST /api/workers/{worker}/deactivate — mark a worker inactive.
     *
     * @param Request $request
     * @param  array<string, string>  $params
     * @return JsonResponse
     */
    public function deactivate(Request $request, array $params): JsonResponse
    {
        $worker = $this->workerService->find($params['worker']);

        if ($worker === null) {
            return $this->response(success: false, message: 'Worker not found.', status: 404);
        }

        $this->workerService->deactivate($worker);

        return $this->response(
            success: true,
            message: 'Worker deactivated successfully.',
        );
    }

    /**
     * POST /api/workers/{worker}/restore — restore a soft-deleted worker.
     *
     * @param Request $request
     * @param  array<string, string>  $params
     * @return JsonResponse
     */
    public function restore(Request $request, array $params): JsonResponse
    {
        $id = $params['worker'];

        // Includes soft-deleted workers (withTrashed), so trashed workers are valid.
        if (! $this->workerService->existsWithTrashed($id)) {
            return $this->response(success: false, message: 'Worker not found.', status: 404);
        }

        $worker = $this->workerService->restore($id);

        return $this->response(
            success: true,
            message: 'Worker restored successfully.',
            data: $worker,
        );
    }

    /**
     * POST /api/workers/delete-all — soft-delete every non-archived worker.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteAll(Request $request): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'All workers deleted successfully.',
            data: ['deleted' => $this->workerService->deleteAll()],
        );
    }

    /**
     * POST /api/workers/restore-all — restore every archived worker as active.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function restoreAll(Request $request): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'All workers restored successfully.',
            data: ['restored' => $this->workerService->restoreAll()],
        );
    }

    /**
     * POST /api/workers/import — upload a CSV; enqueue it for the worker daemon.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function import(Request $request): JsonResponse
    {
        $this->validateUpload($request->file('file'));

        $csv = (string) file_get_contents($request->file('file')['tmp_name']);
        $importId = $this->csv->queueImport($csv);
        $state = $this->csv->getImportState($importId);

        if ($state['status'] === 'completed' || $state['status'] === 'failed') {
            return $this->importStateResponse($state);
        }

        return $this->response(
            success: true,
            message: 'Worker import queued.',
            status: 202,
            data: ['import_id' => $importId, 'status' => 'processing'],
        );
    }

    /**
     * GET /api/workers/import/{import} — poll import status.
     *
     * @param  array<string, string>  $params
     * @return JsonResponse
     */
    public function importStatus(Request $request, array $params): JsonResponse
    {
        return $this->importStateResponse($this->csv->getImportState($params['import']));
    }

    /**
     * GET /api/workers/import/sample — download the CSV template.
     *
     * @param Request $request
     * @return RawResponse
     */
    public function importSample(Request $request): RawResponse
    {
        $path = dirname(__DIR__, 3) . '/database/data/workers-sample.csv';

        return new RawResponse(
            (string) file_get_contents($path),
            'text/csv',
            200,
            ['Content-Disposition' => 'attachment; filename="workers-sample.csv"'],
        );
    }

    /**
     * POST /api/workers/export — enqueue a CSV export for the worker daemon.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function export(Request $request): JsonResponse
    {
        if (! $this->workerService->hasWorkers()) {
            return $this->response(success: false, message: 'No workers to export.', status: 422);
        }

        $exportId = $this->csv->queueExport();
        $state = $this->csv->getExportState($exportId);

        if ($state['status'] === 'completed' || $state['status'] === 'failed') {
            return $this->exportStateResponse($state);
        }

        return $this->response(
            success: true,
            message: 'Worker export queued.',
            status: 202,
            data: ['export_id' => $exportId, 'status' => 'processing'],
        );
    }

    /**
     * GET /api/workers/export/{export} — poll export status.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse
     */
    public function exportStatus(Request $request, array $params): JsonResponse
    {
        return $this->exportStateResponse($this->csv->getExportState($params['export']));
    }

    /**
     * GET /api/workers/export/{export}/download — stream a completed export.
     *
     * @param Request $request
     * @param array<string, string> $params
     * @return JsonResponse|RawResponse
     */
    public function exportDownload(Request $request, array $params): JsonResponse|RawResponse
    {
        $export = $this->csv->takeExport($params['export']);

        if ($export === null) {
            return $this->response(success: false, message: 'Worker export not found or not ready.', status: 404);
        }

        return new RawResponse(
            $export['content'],
            'text/csv',
            200,
            ['Content-Disposition' => 'attachment; filename="' . $export['filename'] . '"'],
        );
    }

    /**
     * Validate the uploaded import file, for uploaded import files
     * (required|file|extension:csv|max:10240). Throws on failure.
     *
     * @param  array{name: string, type: string, tmp_name: string, error: int, size: int}|null  $file
     * @return void
     */
    private function validateUpload(?array $file): void
    {
        $fail = static function (string $message): void {
            throw new ValidationException($message, ['file' => [$message]]);
        };

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $fail('The file field is required.');
        }
        if ($file['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($file['tmp_name'])) {
            $fail('The file field must be a file.');
        }

        // CSV only. CSV content sniffs as text/plain (indistinguishable from a
        // .txt file), so the extension is the reliable signal — gate on it.
        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            $fail('The file field must be a file of type: csv.');
        }

        // max:10240 kilobytes.
        if ($file['size'] > 10240 * 1024) {
            $fail('The file field must not be greater than 10240 kilobytes.');
        }
    }

    /**
     * Map an import state to its HTTP response.
     *
     * @param array<string, mixed> $state
     * @return JsonResponse
     */
    private function importStateResponse(array $state): JsonResponse
    {
        return match ($state['status']) {
            'not_found' => $this->response(success: false, message: 'Worker import not found.', status: 404),
            'processing' => $this->response(
                success: true,
                message: 'Worker import is processing.',
                status: 200,
                data: ['import_id' => $state['import_id'], 'status' => 'processing'],
            ),
            'completed' => $this->response(
                success: true,
                message: 'Worker import processed.',
                status: 200,
                data: $state['data'],
                errors: $state['errors'] ?? [],
            ),
            'failed' => $this->response(
                success: false,
                message: 'Worker import failed.',
                status: 500,
                data: ['import_id' => $state['import_id'], 'status' => 'failed', 'message' => $state['message'] ?? 'Unknown error.'],
            ),
        };
    }

    /**
     * Map an export state to its HTTP response.
     *
     * @param array<string, mixed> $state
     * @return JsonResponse
     */
    private function exportStateResponse(array $state): JsonResponse
    {
        return match ($state['status']) {
            'not_found' => $this->response(success: false, message: 'Worker export not found.', status: 404),
            'processing' => $this->response(
                success: true,
                message: 'Worker export is processing.',
                status: 200,
                data: ['export_id' => $state['export_id'], 'status' => 'processing'],
            ),
            'completed' => $this->response(
                success: true,
                message: 'Worker export processed.',
                status: 200,
                data: $state['data'],
            ),
            'failed' => $this->response(
                success: false,
                message: 'Worker export failed.',
                status: 500,
                data: ['export_id' => $state['export_id'], 'status' => 'failed', 'message' => $state['message'] ?? 'Unknown error.'],
            ),
        };
    }

    /**
     * The contract-hours conflict response (422 in the standard envelope).
     *
     * @param WorkerContractException $e
     * @return JsonResponse
     */
    private function contractError(WorkerContractException $e): JsonResponse
    {
        return $this->response(
            success: false,
            message: $e->getMessage(),
            status: 422,
            errors: ['contract.max_monthly_hours' => [$e->getMessage()]],
        );
    }

    /**
     * Validation rules for creating a worker (mirrors StoreWorkerRequest, in
     *
     * @return array<string, list<string>>
     */
    private static function storeRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'israeli_id' => ['required', 'string', 'israeli_id', 'unique:workers,israeli_id'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'is_active' => ['required', 'boolean'],
            'contract' => ['required', 'array'],
            'contract.hourly_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'contract.min_monthly_hours' => ['required', 'integer', 'min:0', 'max:744'],
            'contract.max_monthly_hours' => ['required', 'integer', 'min:0', 'max:744', 'gte:contract.min_monthly_hours'],
            'availability' => ['required', 'array', 'min:1'],
            'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'availability.*.shift_id' => ['required', 'integer', 'exists:shifts,id'],
        ];
    }

    /**
     * Validation rules for updating a worker
     *
     * @return array<string, list<string>>
     */
    private static function updateRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'is_active' => ['required', 'boolean'],
            'contract' => ['required', 'array'],
            'contract.hourly_cost' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'contract.min_monthly_hours' => ['required', 'integer', 'min:0', 'max:744'],
            'contract.max_monthly_hours' => ['required', 'integer', 'min:0', 'max:744', 'gte:contract.min_monthly_hours'],
            'availability' => ['required', 'array', 'min:1'],
            'availability.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'availability.*.shift_id' => ['required', 'integer', 'exists:shifts,id'],
        ];
    }
}
