<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportWorkersRequest;
use App\Http\Requests\StoreWorkerRequest;
use App\Http\Requests\UpdateWorkerRequest;
use App\Http\Resources\WorkerResource;
use App\Models\Worker;
use App\Services\Workers\Csv\WorkerCsvService;
use App\Services\Workers\WorkerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WorkerController extends Controller
{
    /**
     * Constructor.
     *
     * @param WorkerService $workerService
     * @param WorkerCsvService $csvService
     */
    public function __construct(
        private readonly WorkerService $workerService,
        private readonly WorkerCsvService $csvService,
    ) {
    }

    /**
     * Return reference values needed by worker forms.
     *
     * @return JsonResponse
     */
    public function referenceData(): JsonResponse
    {
        return $this->response(
            success: true,
            message: 'Reference data retrieved successfully.',
            status: 200,
            data: $this->workerService->referenceData(),
        );
    }

    /**
     * Queue a worker CSV import and return the result when already finished.
     *
     * @param ImportWorkersRequest $request
     * @return JsonResponse
     */
    public function import(ImportWorkersRequest $request): JsonResponse
    {
        $importId = $this->csvService->queueImport($request->file('file'));
        $state = $this->csvService->getImportState($importId);

        if ($state['status'] === 'completed' || $state['status'] === 'failed') {
            return $this->importStateResponse($state);
        }

        return $this->response(
            success: true,
            message: 'Worker import queued.',
            status: 202,
            data: [
                'import_id' => $importId,
                'status' => 'processing',
            ],
        );
    }

    /**
     * Return the status of a queued worker CSV import.
     *
     * @param string $importId
     * @return JsonResponse
     */
    public function importStatus(string $importId): JsonResponse
    {
        return $this->importStateResponse($this->csvService->getImportState($importId));
    }

    /**
     * Export all workers as a streamed, re-importable CSV download.
     *
     * @return StreamedResponse
     */
    public function export(): StreamedResponse
    {
        return $this->csvService->streamExport();
    }

    /**
     * @param array{
     *     status: 'not_found'|'processing'|'completed'|'failed',
     *     import_id: string,
     *     data?: array<string, mixed>,
     *     errors?: list<array{line: int, field: string, message: string}>,
     *     message?: string
     * } $state
     */
    private function importStateResponse(array $state): JsonResponse
    {
        return match ($state['status']) {
            'not_found' => $this->response(
                success: false,
                message: 'Worker import not found.',
                status: 404,
            ),
            'processing' => $this->response(
                success: true,
                message: 'Worker import is processing.',
                status: 200,
                data: [
                    'import_id' => $state['import_id'],
                    'status' => 'processing',
                ],
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
                data: [
                    'import_id' => $state['import_id'],
                    'status' => 'failed',
                    'message' => $state['message'] ?? 'Unknown error.',
                ],
            ),
        };
    }

    /**
     * Display a listing of the resource.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $workers = $this->workerService->list($request);

        return $this->response(
            success: true,
            message: 'Workers retrieved successfully.',
            status: 200,
            data: WorkerResource::collection($workers->items()),
            meta: [
                'current_page' => $workers->currentPage(),
                'from' => $workers->firstItem(),
                'last_page' => $workers->lastPage(),
                'per_page' => $workers->perPage(),
                'to' => $workers->lastItem(),
                'total' => $workers->total(),
            ],
        );
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreWorkerRequest $request
     * @return JsonResponse
     */
    public function store(StoreWorkerRequest $request): JsonResponse
    {
        $worker = $this->workerService->create($request->validated());

        return $this->response(
            success: true,
            message: 'Worker created successfully.',
            status: 201,
            data: WorkerResource::make($worker),
        );
    }

    /**
     * Display the specified resource.
     *
     * @param Worker $worker
     * @return JsonResponse
     */
    public function show(Worker $worker): JsonResponse
    {
        $worker = $this->workerService->loadDetails($worker);

        return $this->response(
            success: true,
            message: 'Worker retrieved successfully.',
            status: 200,
            data: WorkerResource::make($worker),
        );
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateWorkerRequest $request
     * @param Worker $worker
     * @return JsonResponse
     */
    public function update(UpdateWorkerRequest $request, Worker $worker): JsonResponse
    {
        $worker = $this->workerService->update($worker, $request->validated());

        return $this->response(
            success: true,
            message: 'Worker updated successfully.',
            status: 200,
            data: WorkerResource::make($worker),
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Worker $worker
     * @return JsonResponse
     */
    public function destroy(Worker $worker): JsonResponse
    {
        $this->workerService->delete($worker);

        return $this->response(
            success: true,
            message: 'Worker deleted successfully.',
            status: 200,
        );
    }

    /**
     * Remove every worker from storage.
     *
     * @return JsonResponse
     */
    public function destroyAll(): JsonResponse
    {
        $deleted = $this->workerService->deleteAll();

        return $this->response(
            success: true,
            message: 'All workers deleted successfully.',
            status: 200,
            data: [
                'deleted' => $deleted,
            ],
        );
    }
}
