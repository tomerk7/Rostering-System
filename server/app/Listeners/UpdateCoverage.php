<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\WorkersImported;
use App\Services\Rostering\RosterReportService;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * Update the coverage shortages for all rosters.
 */
final readonly class UpdateCoverage implements ShouldQueueAfterCommit
{
    /**
     * Constructor.
     * 
     * @param RosterReportService $reportService
     * @return void
     */
    public function __construct(
        private RosterReportService $reportService,
    ) {}

    /**
     * Handle the event.
     * 
     * @param WorkersImported $event
     * @return void
     */
    public function handle(WorkersImported $event): void
    {
        $this->reportService->refreshAllCoverageShortages();
    }
}
