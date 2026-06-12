<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Jobs\ExportWorkersJob;
use App\Jobs\ImportWorkersJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Worker CSV import/export and queued import/export orchestration.
 *
 * Fixed columns 0-6 hold worker/contract fields. Columns 7+ are shift columns
 * identified by header label (e.g. 08:00-16:00). Each shift cell holds
 * a cron-style day expression using days 1-7 (1=Sunday .. 7=Saturday).
 */
final class WorkerCsvService
{
    private const string IMPORT_STORAGE_DIR = 'worker-imports';

    public function __construct(
        private readonly WorkerCsvImporter $importer,
        private readonly WorkerCsvExporter $exporter,
    ) {}

    /**
     * Build the full CSV header row: fixed columns plus one column per shift.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return $this->exporter->headers();
    }

    /**
     * Store an uploaded CSV and queue it for import.
     * 
     * @param UploadedFile $file
     * @return string
     */
    public function queueImport(UploadedFile $file): string
    {
        $this->purgeAbandonedImportFiles();

        $importId = (string) Str::uuid();

        Cache::put($this->importCacheKey($importId), [
            'status' => 'processing',
        ], now()->addHour());

        $storedPath = $file->storeAs(self::IMPORT_STORAGE_DIR, "{$importId}.csv", 'local');

        ImportWorkersJob::dispatch($importId, $storedPath);

        return $importId;
    }

    /**
     * Process a queued worker CSV import.
     * 
     * @param string $importId
     * @param string $storedPath
     * @return void
     */
    public function processImport(string $importId, string $storedPath): void
    {
        $absolutePath = Storage::disk('local')->path($storedPath);

        try {
            $result = $this->importer->import($absolutePath);

            Cache::put($this->importCacheKey($importId), [
                'status' => 'completed',
                'result' => $result,
            ], now()->addHour());
        } finally {
            $this->removeImportFile($storedPath);
        }
    }

    /**
     * Remove a stored import CSV from disk.
     * 
     * @param string $storedPath
     * @return void
     */
    public function removeImportFile(string $storedPath): void
    {
        if (! str_starts_with($storedPath, self::IMPORT_STORAGE_DIR.'/')) {
            Log::warning('Skipped deleting import file outside the import directory.', [
                'stored_path' => $storedPath,
            ]);

            return;
        }

        if (! Storage::disk('local')->delete($storedPath)) {
            return;
        }

        Log::info('Import file deleted.', ['stored_path' => $storedPath]);
    }

    /**
     * Delete import CSVs left behind when a queued job never ran.
     *
     * @return void
     */
    private function purgeAbandonedImportFiles(): void
    {
        $disk = Storage::disk('local');

        if (! $disk->exists(self::IMPORT_STORAGE_DIR)) {
            return;
        }

        foreach ($disk->files(self::IMPORT_STORAGE_DIR) as $path) {
            if (! $this->isAbandonedImportFile($path)) {
                continue;
            }

            $disk->delete($path);
            Log::info('Abandoned import file deleted.', ['stored_path' => $path]);
        }
    }

    /**
     * Determine whether a stored import CSV was left behind after its job finished or never ran.
     *
     * @param string $storedPath
     * @return bool
     */
    private function isAbandonedImportFile(string $storedPath): bool
    {
        $importId = basename($storedPath, '.csv');

        if (! Str::isUuid($importId)) {
            return false;
        }

        $cached = Cache::get($this->importCacheKey($importId));

        if (! is_array($cached)) {
            return true;
        }

        return ($cached['status'] ?? '') !== 'processing';
    }

    /**
     * Record a failed worker CSV import and remove the stored file.
     * 
     * @param string $importId
     * @param string $storedPath
     * @param string $message
     * @return void
     */
    public function markImportFailed(string $importId, string $storedPath, string $message): void
    {
        Cache::put($this->importCacheKey($importId), [
            'status' => 'failed',
            'message' => $message,
        ], now()->addHour());

        $this->removeImportFile($storedPath);
    }

    /**
     * Return the current state of a queued worker CSV import.
     *
     * @return array{
     *     status: 'not_found'|'processing'|'completed'|'failed',
     *     import_id: string,
     *     data?: array<string, mixed>,
     *     errors?: list<array{line: int, field: string, message: string}>,
     *     message?: string
     * }
     */
    public function getImportState(string $importId): array
    {
        $cached = Cache::get($this->importCacheKey($importId));

        if (! is_array($cached)) {
            return [
                'status' => 'not_found',
                'import_id' => $importId,
            ];
        }

        return match ($cached['status']) {
            'processing' => [
                'status' => 'processing',
                'import_id' => $importId,
            ],
            'completed' => $this->completedImportState($importId, $cached['result']),
            'failed' => [
                'status' => 'failed',
                'import_id' => $importId,
                'message' => $cached['message'] ?? 'Unknown error.',
            ],
            default => [
                'status' => 'not_found',
                'import_id' => $importId,
            ],
        };
    }

    /**
     * Queue a worker CSV export.
     * 
     * @return string
     */
    public function queueExport(): string
    {
        $exportId = (string) Str::uuid();

        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'processing',
        ], now()->addHour());

        ExportWorkersJob::dispatch($exportId);

        return $exportId;
    }

    /**
     * Process a queued worker CSV export.
     *
     * @param string $exportId
     * @return void
     */
    public function processExport(string $exportId): void
    {
        $handle = fopen('php://temp', 'r+');

        if (! $handle) {
            throw new RuntimeException('Unable to open export buffer for writing.');
        }

        try {
            $this->exporter->writeTo($handle);
            rewind($handle);
            $content = stream_get_contents($handle) ?: '';
        } finally {
            fclose($handle);
        }

        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'completed',
            'content' => $content,
            'filename' => 'workers-'.now()->format('Y-m-d').'.csv',
        ], now()->addHour());
    }

    /**
     * Record a failed worker CSV export.
     *
     * @param string $exportId
     * @param string $message
     * @return void
     */
    public function markExportFailed(string $exportId, string $message): void
    {
        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'failed',
            'message' => $message,
        ], now()->addHour());
    }

    /**
     * Return the current state of a queued worker CSV export.
     *
     * @param string $exportId
     * @return array{
     *     status: 'not_found'|'processing'|'completed'|'failed',
     *     export_id: string,
     *     data?: array<string, mixed>,
     *     message?: string
     * }
     */
    public function getExportState(string $exportId): array
    {
        $cached = Cache::get($this->exportCacheKey($exportId));

        if (! is_array($cached)) {
            return [
                'status' => 'not_found',
                'export_id' => $exportId,
            ];
        }

        return match ($cached['status']) {
            'processing' => [
                'status' => 'processing',
                'export_id' => $exportId,
            ],
            'completed' => [
                'status' => 'completed',
                'export_id' => $exportId,
                'data' => [
                    'export_id' => $exportId,
                    'status' => 'completed',
                    'filename' => $cached['filename'],
                ],
            ],
            'failed' => [
                'status' => 'failed',
                'export_id' => $exportId,
                'message' => $cached['message'] ?? 'Unknown error.',
            ],
            default => [
                'status' => 'not_found',
                'export_id' => $exportId,
            ],
        };
    }

    /**
     * Stream a completed queued export from cache.
     *
     * @param string $exportId
     * @return StreamedResponse
     */
    public function streamQueuedExport(string $exportId): StreamedResponse
    {
        $cached = Cache::get($this->exportCacheKey($exportId));

        if (! is_array($cached) || ($cached['status'] ?? '') !== 'completed') {
            abort(404, 'Worker export not found or not ready.');
        }

        /** @var string $content */
        $content = $cached['content'];
        /** @var string $filename */
        $filename = $cached['filename'];

        return response()->streamDownload(
            function () use ($content, $exportId): void {
                echo $content;
                Cache::forget($this->exportCacheKey($exportId));
            },
            $filename,
            ['Content-Type' => 'text/csv'],
        );
    }

    /**
     * @param string $importId
     * @param array{
     *     total: int,
     *     imported: int,
     *     created: int,
     *     updated: int,
     *     skipped: int,
     *     errors: list<array{line: int, field: string, message: string}>
     * } $result
     * @return array{
     *     status: 'completed',
     *     import_id: string,
     *     data: array<string, mixed>,
     *     errors: list<array{line: int, field: string, message: string}>
     * }
     */
    private function completedImportState(string $importId, array $result): array
    {
        $errors = $result['errors'];
        unset($result['errors']);

        return [
            'status' => 'completed',
            'import_id' => $importId,
            'data' => $result,
            'errors' => $errors,
        ];
    }

    /**
     * Build a cache key for a queued worker CSV import.
     * 
     * @param string $importId
     * @return string
     */
    private function importCacheKey(string $importId): string
    {
        return "worker-import:{$importId}";
    }

    /**
     * Build a cache key for a queued worker CSV export.
     * 
     * @param string $exportId
     * @return string
     */
    private function exportCacheKey(string $exportId): string
    {
        return "worker-export:{$exportId}";
    }
}
