<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Rostering\RosterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateRosterJob implements ShouldQueue
{
    use Queueable;

    /**
     * Run the generation once; a partial run is not safely re-runnable as a retry.
     */
    public int $tries = 1;

    /**
     * Allow a full month's greedy construction to finish well beyond the 60s default.
     */
    public int $timeout = 1800;

    /**
     * Surface a timeout as a failure so the generation record is updated.
     */
    public bool $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly string $generationUuid,
    ) {
    }

    /**
     * Execute the job.
     *
     * @param RosterService $rosterService
     * @return void
     */
    public function handle(RosterService $rosterService): void
    {
        $rosterService->processGeneration($this->generationUuid);
    }

    /**
     * Handle a job failure.
     *
     * @param Throwable|null $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Generate roster job failed.', [
            'generation_uuid' => $this->generationUuid,
            'exception' => $exception,
        ]);

        app(RosterService::class)->deleteGeneration($this->generationUuid);
    }
}
