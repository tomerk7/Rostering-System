<?php

declare(strict_types=1);

namespace App\Services\Rostering\Csv;

use App\Data\Roster;
use App\Services\RosterReportService;

/**
 * Build a roster CSV (header + one row per assigned worker) as a string.
 *
 * Rows come from RosterReportService so the CSV can never disagree with the stats
 * screen; this class only owns the column order and string formatting. Mirrors
 * Returns the whole string
 * since the vanilla queue stores the content in the DB. fputcsv on a temp handle
 * keeps the encoding (quoting/escaping/newlines) consistent.
 */
class RosterCsvExporter
{
    /**
     * @var list<string>
     */
    public const array HEADERS = [
        'worker_id',
        'worker_name',
        'roster_year',
        'roster_month',
        'min_hours',
        'max_hours',
        'actual_hours',
        'total_cost',
    ];

    /**
     * Class constructor.
     *
     * @param RosterReportService $reportService
     */
    public function __construct(
        private RosterReportService $reportService = new RosterReportService,
    ) {}

    /**
     * Render the full CSV (header + assigned-worker rows) for a roster.
     *
     * @param Roster $roster
     * @return string
     */
    public function toString(Roster $roster): string
    {
        $year = (int) substr($roster->periodStart, 0, 4);
        $month = (int) substr($roster->periodStart, 5, 2);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADERS);

        foreach ($this->reportService->forRoster($roster)['rows'] as $row) {
            fputcsv($handle, [
                $row['worker_id'],
                $row['name'],
                (string) $year,
                (string) $month,
                (string) $row['min_hours'],
                (string) $row['max_hours'],
                (string) $row['actual_hours'],
                number_format((float) $row['total_cost'], 2, '.', ''),
            ]);
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
    }
}
