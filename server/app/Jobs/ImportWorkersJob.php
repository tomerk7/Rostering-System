<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Workers\Csv\WorkerCsvService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

final class ImportWorkersJob implements ShouldQueue
{
    use Queueable;

    /**
     * Run the import once; a partial import is not safely re-runnable as a retry.
     */
    public int $tries = 1;

    /**
     * Allow large files to finish; a 10k-row import far exceeds the 60s default.
     */
    public int $timeout = 1800;

    /**
     * Surface a timeout as a failure so the cached import state is updated.
     */
    public bool $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $importId,
        private readonly string $storedPath,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(WorkerCsvService $csvService): void
    {
        $csvService->processImport($this->importId, $this->storedPath);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        app(WorkerCsvService::class)->markImportFailed(
            $this->importId,
            $this->storedPath,
            $exception?->getMessage() ?? 'Unknown error.',
        );
    }
}
