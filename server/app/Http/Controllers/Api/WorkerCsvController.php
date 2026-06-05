<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportWorkersRequest;
use App\Services\Workers\Csv\WorkerCsvExporter;
use App\Services\Workers\Csv\WorkerCsvImporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class WorkerCsvController extends Controller
{
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
}
