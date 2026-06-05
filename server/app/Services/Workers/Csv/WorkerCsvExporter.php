<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Models\Worker;

final class WorkerCsvExporter
{
    /**
     * Stream every worker to the given handle as a re-importable CSV.
     *
     * Writes the header row followed by one row per worker in the exact column
     * order the importer expects. Workers are read with `lazy()` (chunked,
     * eager-loaded) so memory stays flat regardless of workforce size.
     *
     * @param  resource  $handle  An open, writable stream (e.g. php://output).
     */
    public function writeTo($handle): void
    {
        fputcsv($handle, WorkerCsvFormat::HEADERS);

        Worker::query()
            ->with(['role', 'contract.availableDays', 'contract.availableShifts'])
            ->orderBy('israeli_id')
            ->lazy()
            ->each(function (Worker $worker) use ($handle): void {
                fputcsv($handle, $this->toRow($worker));
            });
    }

    /**
     * Build a single CSV row for a worker in fixed column order.
     *
     * @return array<int, string>
     */
    private function toRow(Worker $worker): array
    {
        $contract = $worker->contract;

        $row = [
            WorkerCsvFormat::FULL_NAME => $worker->full_name,
            WorkerCsvFormat::ISRAELI_ID => $worker->israeli_id,
            WorkerCsvFormat::ROLE => WorkerCsvFormat::ROLE_LABEL_BY_CODE[$worker->role->code] ?? $worker->role->code,
            WorkerCsvFormat::STATUS => $worker->is_active
                ? WorkerCsvFormat::STATUS_ACTIVE
                : WorkerCsvFormat::STATUS_INACTIVE,
            WorkerCsvFormat::HOURLY_COST => (string) $contract?->hourly_cost,
            WorkerCsvFormat::MIN_MONTHLY_HOURS => (string) $contract?->min_monthly_hours,
            WorkerCsvFormat::MAX_MONTHLY_HOURS => (string) $contract?->max_monthly_hours,
            WorkerCsvFormat::AVAILABLE_DAYS => $this->days($contract),
            WorkerCsvFormat::AVAILABLE_SHIFTS => $this->shifts($contract),
        ];

        ksort($row);

        return $row;
    }

    /**
     * Pipe-separated day tokens, ordered Sun..Sat for a stable round-trip.
     */
    private function days(?object $contract): string
    {
        if ($contract === null) {
            return '';
        }

        $days = $contract->availableDays
            ->pluck('day_of_week')
            ->map(static fn (mixed $day): int => (int) $day)
            ->sort()
            ->map(static fn (int $day): string => WorkerCsvFormat::DAY_TOKEN_BY_NUMBER[$day])
            ->all();

        return implode(WorkerCsvFormat::VALUE_SEPARATOR, $days);
    }

    /**
     * Pipe-separated shift codes, ordered A..C for a stable round-trip.
     */
    private function shifts(?object $contract): string
    {
        if ($contract === null) {
            return '';
        }

        $codes = $contract->availableShifts
            ->pluck('code')
            ->map(static fn (mixed $code): string => (string) $code)
            ->sort()
            ->values()
            ->all();

        return implode(WorkerCsvFormat::VALUE_SEPARATOR, $codes);
    }
}
