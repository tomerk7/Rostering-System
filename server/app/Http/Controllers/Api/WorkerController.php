<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportWorkersRequest;
use App\Http\Requests\StoreWorkerRequest;
use App\Http\Requests\UpdateWorkerRequest;
use App\Http\Resources\WorkerResource;
use App\Models\Worker;
use App\Services\Workers\Csv\WorkerCsvExporter;
use App\Services\Workers\Csv\WorkerCsvImporter;
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
     */
    public function __construct(private readonly WorkerService $workerService)
    {
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
     * Import workers from an uploaded CSV file.
     *
     * @param ImportWorkersRequest $request
     * @param WorkerCsvImporter $importer
     * @return JsonResponse
     */
    public function import(ImportWorkersRequest $request, WorkerCsvImporter $importer): JsonResponse
    {
        $path = $request->file('file')->getRealPath();

        $result = $importer->import($path);

        $errors = $result['errors'];
        unset($result['errors']);

        return $this->response(
            success: true,
            message: 'Worker import processed.',
            status: 200,
            data: $result,
            errors: $errors,
        );
    }

    /**
     * Export all workers as a streamed, re-importable CSV download.
     *
     * @param WorkerCsvExporter $exporter
     * @return StreamedResponse
     */
    public function export(WorkerCsvExporter $exporter): StreamedResponse
    {
        $filename = 'workers-' . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(
            function () use ($exporter): void {
                $handle = fopen('php://output', 'w');
                $exporter->writeTo($handle);
                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv'],
        );
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
}
