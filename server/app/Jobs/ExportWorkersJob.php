<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Workers\Csv\WorkerCsvService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
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
     */
    public function handle(WorkerCsvService $csvService): void
    {
        $csvService->processExport($this->exportId, $this->storedPath);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        app(WorkerCsvService::class)->markExportFailed(
            $this->exportId,
            $this->storedPath,
            $exception?->getMessage() ?? 'Unknown error.',
        );
    }
}
