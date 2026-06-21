<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Repositories\WorkerCsvJobRepository;
use Random\RandomException;

/**
 * Orchestrates worker CSV import/export: enqueues jobs (FPM side), processes them
 * (worker daemon), and exposes the poll/download state. The queue and status
 * live in `worker_csv_jobs`.
 */
class WorkerCsvService
{
    /**
     * Constructor.
     *
     * @param WorkerCsvJobRepository $jobs
     * @param WorkerCsvImporter $importer
     * @param WorkerCsvExporter $exporter
     */
    public function __construct(
        private WorkerCsvJobRepository $jobs = new WorkerCsvJobRepository,
        private WorkerCsvImporter $importer = new WorkerCsvImporter,
        private WorkerCsvExporter $exporter = new WorkerCsvExporter,
    ) {}

    /**
     * Enqueue an import of the uploaded CSV; returns the import id.
     *
     * @param string $csv
     * @return string
     * @throws RandomException
     */
    public function queueImport(string $csv): string
    {
        return $this->jobs->enqueueImport($csv);
    }

    /**
     * Enqueue an export; returns the export id.
     *
     * @return string
     */
    public function queueExport(): string
    {
        return $this->jobs->enqueueExport();
    }


    /**
     * Process a queued import (worker daemon): parse/validate/upsert, record state.
     *
     * @param string $id
     * @param string $csv
     * @return void
     */
    public function processImport(string $id, string $csv): void
    {
        $path = tempnam(sys_get_temp_dir(), 'worker-import-');

        try {
            file_put_contents($path, $csv);
            $result = $this->importer->import($path);
            $errors = $result['errors'];
            unset($result['errors']);
            $this->jobs->markImportCompleted($id, $result, $errors);
        } finally {
            @unlink($path);
        }
    }


    /**
     * Process a queued export (worker daemon): build the CSV, record state.
     *
     * @param string $id
     * @return void
     */
    public function processExport(string $id): void
    {
        $content = $this->exporter->toString();
        $this->jobs->markExportCompleted($id, 'workers-' . gmdate('Y-m-d') . '.csv', $content);
    }

    /**
     * Current import state for polling.
     *
     * @param string $id
     * @return array<string, mixed>
     */
    public function getImportState(string $id): array
    {
        $job = $this->jobs->find($id);

        if ($job === null) {
            return ['status' => 'not_found', 'import_id' => $id];
        }

        return match ($job['state']) {
            'processing', 'queued' => ['status' => 'processing', 'import_id' => $id],
            'completed' => [
                'status' => 'completed',
                'import_id' => $id,
                'data' => json_decode((string) $job['result'], true),
                'errors' => json_decode((string) $job['errors'], true) ?? [],
            ],
            'failed' => ['status' => 'failed', 'import_id' => $id, 'message' => $job['message'] ?? 'Unknown error.'],
            default => ['status' => 'not_found', 'import_id' => $id],
        };
    }

    /**
     * Current export state for polling.
     *
     * @param string $id
     * @return array<string, mixed>
     */
    public function getExportState(string $id): array
    {
        $job = $this->jobs->find($id);

        if ($job === null) {
            return ['status' => 'not_found', 'export_id' => $id];
        }

        return match ($job['state']) {
            'processing', 'queued' => ['status' => 'processing', 'export_id' => $id],
            'completed' => [
                'status' => 'completed',
                'export_id' => $id,
                'data' => [
                    'export_id' => $id,
                    'status' => 'completed',
                    'filename' => json_decode((string) $job['result'], true)['filename'] ?? 'workers.csv',
                ],
            ],
            'failed' => ['status' => 'failed', 'export_id' => $id, 'message' => $job['message'] ?? 'Unknown error.'],
            default => ['status' => 'not_found', 'export_id' => $id],
        };
    }

    /**
     * Read a completed export's [filename, content] and delete it.
     *
     * @param string $id
     * @return array{filename: string, content: string}|null
     */
    public function takeExport(string $id): ?array
    {
        return $this->jobs->takeExport($id);
    }
}
