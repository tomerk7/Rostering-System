<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Workers\Csv\WorkerCsvService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
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
     * 
     * @param WorkerCsvService $csvService
     * @return void
     */
    public function handle(WorkerCsvService $csvService): void
    {
        Log::info('Import workers job started.', [
            'import_id' => $this->importId,
            'stored_path' => $this->storedPath,
        ]);

        try {
            $csvService->processImport($this->importId, $this->storedPath);

            Log::info('Import workers job completed.', [
                'import_id' => $this->importId,
                'stored_path' => $this->storedPath,
            ]);
        } finally {
            $csvService->removeImportFile($this->storedPath);
        }
    }

    /**
     * Handle a job failure.
     * 
     * @param Throwable|null $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Import workers job failed.', [
            'import_id' => $this->importId,
            'stored_path' => $this->storedPath,
            'exception' => $exception,
        ]);

        app(WorkerCsvService::class)->markImportFailed(
            $this->importId,
            $this->storedPath,
            $exception?->getMessage() ?? 'Unknown error.',
        );
    }
}
