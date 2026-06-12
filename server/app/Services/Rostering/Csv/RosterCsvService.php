<?php

declare(strict_types=1);

namespace App\Services\Rostering\Csv;

use App\Exceptions\Rostering\RosterExportException;
use App\Jobs\ExportRosterJob;
use App\Models\Roster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Roster CSV export orchestration.
 */
final class RosterCsvService
{
    /**
     * Constructor.
     * 
     * @param RosterCsvExporter $exporter
     * @return void
     */
    public function __construct(
        private readonly RosterCsvExporter $exporter,
    ) {}

    /**
     * Queue a roster CSV export.
     *
     * @param Roster $roster
     * @return string
     * @throws RosterExportException
     */
    public function queueExport(Roster $roster): string
    {
        $this->assertFullyAssigned($roster);

        $exportId = (string) Str::uuid();

        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'processing',
            'roster_id' => $roster->id,
        ], now()->addHour());

        ExportRosterJob::dispatch($exportId, $roster->id);

        return $exportId;
    }

    /**
     * Process a queued roster CSV export.
     *
     * @param string $exportId
     * @param int $rosterId
     * @return void
     * @throws RosterExportException
     */
    public function processExport(string $exportId, int $rosterId): void
    {
        $roster = Roster::query()->findOrFail($rosterId);

        $this->assertFullyAssigned($roster);

        $handle = fopen('php://temp', 'r+');

        if (! $handle) {
            throw new RuntimeException('Unable to open export buffer for writing.');
        }

        try {
            $this->exporter->writeTo($handle, $roster);
            rewind($handle);
            $content = stream_get_contents($handle) ?: '';
        } finally {
            fclose($handle);
        }

        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'completed',
            'content' => $content,
            'filename' => sprintf('roster-%d-%02d.csv', $roster->year, $roster->month),
            'roster_id' => $roster->id,
        ], now()->addHour());
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
        $cached = Cache::get($this->exportCacheKey($exportId));
        $rosterId = is_array($cached) ? ($cached['roster_id'] ?? null) : null;

        Cache::put($this->exportCacheKey($exportId), [
            'status' => 'failed',
            'message' => $message,
            'roster_id' => $rosterId,
        ], now()->addHour());
    }

    /**
     * Return the current state of a queued roster CSV export.
     * 
     * @param Roster $roster
     * @param string $exportId
     * @return array{
     *     status: 'not_found'|'processing'|'completed'|'failed',
     *     export_id: string,
     *     data?: array<string, mixed>,
     *     message?: string
     * }
     */
    public function getExportState(Roster $roster, string $exportId): array
    {
        $cached = Cache::get($this->exportCacheKey($exportId));

        if (! is_array($cached) || ($cached['roster_id'] ?? null) !== $roster->id) {
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
     * @param Roster $roster
     * @param string $exportId
     * @return StreamedResponse
     */
    public function streamQueuedExport(Roster $roster, string $exportId): StreamedResponse
    {
        $cached = Cache::get($this->exportCacheKey($exportId));

        if (
            ! is_array($cached)
            || ($cached['status'] ?? '') !== 'completed'
            || ($cached['roster_id'] ?? null) !== $roster->id
        ) {
            abort(404, 'Roster export not found or not ready.');
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
     * Ensure the roster has no coverage shortages before export.
     *
     * @throws RosterExportException
     */
    private function assertFullyAssigned(Roster $roster): void
    {
        if ($roster->coverageShortages()->exists()) {
            throw RosterExportException::coverageShortages();
        }
    }

    /**
     * Build a cache key for a queued roster CSV export.
     * 
     * @param string $exportId
     * @return string
     */
    private function exportCacheKey(string $exportId): string
    {
        return "roster-export:{$exportId}";
    }
}
