<?php

declare(strict_types=1);

namespace App\Services\Rostering\Csv;

use App\Models\Roster;
use Illuminate\Support\Collection;

/**
 * Build roster CSV headers and rows and write them to a file handle.
 */
final class RosterCsvExporter
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

        foreach ($this->assignedWorkers($roster) as $row) {
            fputcsv($handle, $this->toRow($roster, $row));
        }
    }

    /**
     * Load assigned workers with contract fields and monthly actual hours.
     * 
     * @param Roster $roster
     * @return Collection<int, object{
     *     israeli_id: string,
     *     full_name: string,
     *     min_monthly_hours: int|null,
     *     max_monthly_hours: int|null,
     *     hourly_cost: string|null,
     *     actual_hours: int
     * }>
     */
    private function assignedWorkers(Roster $roster): Collection
    {
        /** @var Collection<int, object> $rows */
        $rows = $roster->assignments()
            ->join('shifts', 'shifts.id', '=', 'roster_assignments.shift_id')
            ->join('workers', 'workers.israeli_id', '=', 'roster_assignments.worker_id')
            ->leftJoin('contracts', 'contracts.worker_id', '=', 'workers.israeli_id')
            ->selectRaw(
                'workers.israeli_id,
                workers.full_name,
                contracts.min_monthly_hours,
                contracts.max_monthly_hours,
                contracts.hourly_cost,
                SUM(shifts.duration_hours) AS actual_hours',
            )
            ->groupBy(
                'workers.israeli_id',
                'workers.full_name',
                'contracts.min_monthly_hours',
                'contracts.max_monthly_hours',
                'contracts.hourly_cost',
            )
            ->orderBy('workers.israeli_id')
            ->get();

        return $rows;
    }

    /**
     * Build a single CSV row for an assigned worker.
     *
     * @param Roster $roster
     * @param  object{
     *     israeli_id: string,
     *     full_name: string,
     *     min_monthly_hours: int|null,
     *     max_monthly_hours: int|null,
     *     hourly_cost: string|null,
     *     actual_hours: int
     * }  $row
     * @return list<string>
     */
    private function toRow(Roster $roster, object $row): array
    {
        $actualHours = (int) $row->actual_hours;
        $minHours = (int) ($row->min_monthly_hours ?? 0);
        $maxHours = (int) ($row->max_monthly_hours ?? 0);
        $hourlyCost = (float) ($row->hourly_cost ?? 0);

        return [
            (string) $row->israeli_id,
            (string) $row->full_name,
            (string) $roster->year,
            (string) $roster->month,
            (string) $minHours,
            (string) $maxHours,
            (string) $actualHours,
            $this->formatPercent($actualHours, $maxHours, capAtHundred: false),
            $this->formatPercent($actualHours, $minHours, capAtHundred: true),
            number_format($actualHours * $hourlyCost, 2, '.', ''),
        ];
    }

    /**
     * Format a utilization percentage with division-by-zero protection.
     * 
     * @param int $actualHours
     * @param int $targetHours
     * @param bool $capAtHundred
     * @return string
     */
    private function formatPercent(int $actualHours, int $targetHours, bool $capAtHundred): string
    {
        if ($targetHours <= 0) {
            return '0.00';
        }

        $percent = ($actualHours / $targetHours) * 100;

        if ($capAtHundred) {
            $percent = min($percent, 100);
        }

        return number_format($percent, 2, '.', '');
    }
}
