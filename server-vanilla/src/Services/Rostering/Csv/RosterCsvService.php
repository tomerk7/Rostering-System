<?php

declare(strict_types=1);

namespace App\Services\Rostering\Csv;

use App\Exceptions\RosterExportException;
use App\Repositories\CoverageShortageRepository;
use App\Repositories\RosterExportJobRepository;
use App\Repositories\RosterRepository;
use Random\RandomException;
use RuntimeException;

/**
 * Roster CSV export orchestration: enqueues exports (FPM side), processes them
 * (worker daemon), and exposes the poll/download state. The queue and content
 * live in `roster_export_jobs` instead of the cache.
 */
class RosterCsvService
{
    /**
     * Class constructor.
     *
     * @param RosterExportJobRepository $jobs
     * @param RosterCsvExporter $exporter
     * @param RosterRepository $rosters
     * @param CoverageShortageRepository $coverageShortages
     */
    public function __construct(
        private RosterExportJobRepository $jobs = new RosterExportJobRepository,
        private RosterCsvExporter $exporter = new RosterCsvExporter,
        private RosterRepository $rosters = new RosterRepository,
        private CoverageShortageRepository $coverageShortages = new CoverageShortageRepository,
    ) {}

    /**
     * Queue a roster CSV export; returns the export id. Refuses a roster with
     * coverage shortages.
     *
     * @param int $rosterId
     * @return string
     * @throws RosterExportException
     * @throws RandomException
     */
    public function queueExport(int $rosterId): string
    {
        $this->assertFullyAssigned($rosterId);

        return $this->jobs->enqueue($rosterId);
    }

    /**
     * Process a queued export (worker daemon): re-check coverage, render the CSV,
     * record state.
     *
     * @param string $exportId
     * @param int $rosterId
     * @return void
     * @throws RosterExportException
     */
    public function processExport(string $exportId, int $rosterId): void
    {
        $roster = $this->rosters->find($rosterId);

        if ($roster === null) {
            throw new RuntimeException("Roster {$rosterId} not found.");
        }

        $this->assertFullyAssigned($rosterId);

        $year = (int) substr($roster->periodStart, 0, 4);
        $month = (int) substr($roster->periodStart, 5, 2);

        $content = $this->exporter->toString($roster);
        $filename = sprintf('roster-%d-%02d.csv', $year, $month);

        $this->jobs->markCompleted($exportId, $filename, $content);
    }

    /**
     * Record a failed roster CSV export.
     *
     * @param string $exportId
     * @param string $message
     * @return void
     */
    public function markExportFailed(string $exportId, string $message): void
    {
        $this->jobs->markFailed($exportId, $message);
    }

    /**
     * Current export state, scoped to the roster (an export id for another roster
     * reads as not_found).
     *
     * @param int $rosterId
     * @param string $exportId
     * @return array<string, mixed>
     */
    public function getExportState(int $rosterId, string $exportId): array
    {
        $job = $this->jobs->find($exportId);

        if ($job === null || (int) $job['roster_id'] !== $rosterId) {
            return ['status' => 'not_found', 'export_id' => $exportId];
        }

        return match ($job['state']) {
            'queued', 'processing' => ['status' => 'processing', 'export_id' => $exportId],
            'completed' => [
                'status' => 'completed',
                'export_id' => $exportId,
                'data' => [
                    'export_id' => $exportId,
                    'status' => 'completed',
                    'filename' => $job['filename'] ?? 'roster.csv',
                ],
            ],
            'failed' => ['status' => 'failed', 'export_id' => $exportId, 'message' => $job['message'] ?? 'Unknown error.'],
            default => ['status' => 'not_found', 'export_id' => $exportId],
        };
    }

    /**
     * Read a completed export's [filename, content] (scoped to the roster) and
     * delete it.
     *
     * @param int $rosterId
     * @param string $exportId
     * @return array{filename: string, content: string}|null
     */
    public function takeExport(int $rosterId, string $exportId): ?array
    {
        return $this->jobs->takeExport($exportId, $rosterId);
    }

    /**
     * Ensure the roster has no coverage shortages before export.
     *
     * @param int $rosterId
     * @return void
     * @throws RosterExportException
     */
    private function assertFullyAssigned(int $rosterId): void
    {
        if ($this->coverageShortages->existsForRoster($rosterId)) {
            throw RosterExportException::coverageShortages();
        }
    }
}
