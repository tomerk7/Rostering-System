<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Workers\Csv\WorkerCsvService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExportWorkersJob implements ShouldQueue
{
    use Queueable;

    /**
     * Run the export once; a partial export is not safely re-runnable as a retry.
     */
    public int $tries = 1;

    /**
     * Allow large exports to finish; a 10k-row export far exceeds the 60s default.
     */
    public int $timeout = 1800;

    /**
     * Surface a timeout as a failure so the cached export state is updated.
     */
    public bool $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $exportId,
        private readonly string $storedPath,
    ) {
    }

    /**
     * Execute the job.
     * 
     * @param WorkerCsvService $csvService
     * @return void
     */
    public function handle(WorkerCsvService $csvService): void
    {
        $csvService->processExport($this->exportId, $this->storedPath);
    }

    /**
     * Handle a job failure.
     * 
     * @param Throwable|null $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Export workers job failed.', [
            'export_id' => $this->exportId,
            'stored_path' => $this->storedPath,
            'exception' => $exception,
        ]);

        app(WorkerCsvService::class)->markExportFailed(
            $this->exportId,
            $this->storedPath,
            $exception?->getMessage() ?? 'Unknown error.',
        );
    }
}
