<?php

declare(strict_types=1);

namespace App\Services\Rostering\Csv;

use App\Models\Roster;
use App\Services\Rostering\Data\WorkerStatsRow;
use App\Services\Rostering\RosterStatsService;

/**
 * Build roster CSV headers and rows and write them to a file handle.
 *
 * Rows come from RosterStatsService so the CSV can never disagree with the
 * stats screen; this class only owns the column order and string formatting.
 */
final readonly class RosterCsvExporter
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
        'percent_of_max',
        'percent_of_min',
        'total_cost',
    ];

    /**
     * Constructor.
     *
     * @param RosterStatsService $statsService
     * @return void
     */
    public function __construct(private RosterStatsService $statsService) {}

    /**
     * Build the CSV header row.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        return self::HEADERS;
    }

    /**
     * Write the CSV header and all assigned worker rows to the given handle.
     *
     * @param  resource  $handle
     * @param Roster $roster
     * @return void
     */
    public function writeTo($handle, Roster $roster): void
    {
        fputcsv($handle, $this->headers());

        foreach ($this->statsService->forRoster($roster)->rows as $row) {
            fputcsv($handle, $this->toRow($roster, $row));
        }
    }

    /**
     * Build a single CSV row for an assigned worker.
     *
     * @param Roster $roster
     * @param WorkerStatsRow $row
     * @return list<string>
     */
    private function toRow(Roster $roster, WorkerStatsRow $row): array
    {
        return [
            $row->workerId,
            $row->name,
            (string) $roster->year,
            (string) $roster->month,
            (string) $row->minHours,
            (string) $row->maxHours,
            (string) $row->actualHours,
            number_format($row->percentOfMax, 2, '.', ''),
            number_format($row->percentOfMin, 2, '.', ''),
            number_format($row->totalCost, 2, '.', ''),
        ];
    }
}
