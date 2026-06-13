<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Roster;
use App\Services\Rostering\RosterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class GenerateRosterJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        private readonly int $rosterId,
        public readonly bool $optimizeCost = false,
        public readonly ?float $balanceWeight = null,
    ) {}

    /**
     * Execute the job.
     *
     * @param RosterService $rosterService
     * @return void
     */
    public function handle(RosterService $rosterService): void
    {
        $roster = Roster::query()->findOrFail($this->rosterId);

        Log::info('Roster generation job started.', [
            'roster_id' => $this->rosterId,
            'year' => $roster->year,
            'month' => $roster->month,
            'optimize_cost' => $this->optimizeCost,
        ]);

        $rosterService->processGeneration($this->rosterId, $this->optimizeCost, $this->balanceWeight);

        Log::info('Roster generation job completed.', [
            'roster_id' => $this->rosterId,
            'year' => $roster->year,
            'month' => $roster->month,
        ]);
    }

    /**
     * Handle a job failure.
     * 
     * @param Throwable|null $exception
     * @return void
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Roster generation job failed.', [
            'roster_id' => $this->rosterId,
            'exception' => $exception,
        ]);

        $roster = Roster::query()->find($this->rosterId);

        if ($roster !== null) {
            app(RosterService::class)->markGenerationFailed($roster);
        }
    }
}
