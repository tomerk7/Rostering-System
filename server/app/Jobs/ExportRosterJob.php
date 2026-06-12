<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Rostering\Csv\RosterCsvService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExportRosterJob implements ShouldQueue
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
        private readonly int $rosterId,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(RosterCsvService $csvService): void
    {
        $csvService->processExport($this->exportId, $this->rosterId);
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Export roster job failed.', [
            'export_id' => $this->exportId,
            'roster_id' => $this->rosterId,
            'exception' => $exception,
        ]);

        app(RosterCsvService::class)->markExportFailed(
            $this->exportId,
            $exception?->getMessage() ?? 'Unknown error.',
        );
    }
}
