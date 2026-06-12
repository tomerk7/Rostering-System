<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Enums\RoleCode;
use App\Models\Shift;
use App\Models\Worker;

/**
 * Build worker CSV headers and rows and write them to a file handle.
 */
final class WorkerCsvExporter
{
    /**
     * @param WorkerCsvSchema $schema
     * 
     * @return void
     */
    public function __construct(
        private readonly WorkerCsvSchema $schema,
    ) {}

    /**
     * Build the full CSV header row: fixed columns plus one column per shift.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $shiftLabels = $this->schema->orderedShifts()
            ->map(fn (Shift $shift): string => WorkerCsvSchema::shiftColumnLabel($shift))
            ->values()
            ->all();

        return array_merge(WorkerCsvSchema::FIXED_HEADERS, $shiftLabels);
    }

    /**
     * Write the CSV header and all worker rows to the given handle.
     *
     * @param  resource  $handle
     * @return void
     */
    public function writeTo($handle): void
    {
        fputcsv($handle, $this->headers());

        Worker::query()
            ->with(['role', 'contract.availability.shift'])
            ->orderBy('israeli_id')
            ->lazy()
            ->each(function (Worker $worker) use ($handle): void {
                fputcsv($handle, $this->toRow($worker));
            });
    }

    /**
     * Build a single CSV row for a worker in fixed column order.
     *
     * @param Worker $worker
     * @return list<string>
     */
    private function toRow(Worker $worker): array
    {
        $contract = $worker->contract;

        /** @var array<string, list<int>> $daysByShiftCode */
        $daysByShiftCode = [];

        if ($contract !== null) {
            foreach ($contract->availability as $slot) {
                $daysByShiftCode[(string) $slot->shift->code][] = (int) $slot->day_of_week;
            }
        }

        $row = [
            $worker->full_name,
            $worker->israeli_id,
            RoleCode::tryFrom($worker->role->code)?->label() ?? $worker->role->code,
            $worker->is_active ? WorkerCsvSchema::STATUS_ACTIVE : WorkerCsvSchema::STATUS_INACTIVE,
            (string) $contract?->hourly_cost,
            (string) $contract?->min_monthly_hours,
            (string) $contract?->max_monthly_hours,
        ];

        foreach ($this->schema->orderedShifts() as $shift) {
            $days = $daysByShiftCode[$shift->code] ?? [];
            $row[] = $this->compressDays($days);
        }

        return $row;
    }

    /**
     * Compress sorted day numbers into a cron-style expression.
     *
     * @param  list<int>  $days
     */
    private function compressDays(array $days): string
    {
        if ($days === []) {
            return '';
        }

        $days = array_values(array_unique($days));
        sort($days);

        $days = array_map(static fn (int $day): int => $day + 1, $days);

        if ($days === range(1, 7)) {
            return '1-7';
        }

        $parts = [];
        $start = $days[0];
        $previous = $days[0];

        for ($index = 1, $count = count($days); $index < $count; $index++) {
            if ($days[$index] === $previous + 1) {
                $previous = $days[$index];

                continue;
            }

            $parts[] = $start === $previous
                ? (string) $start
                : $start.WorkerCsvSchema::DAY_RANGE_SEPARATOR.$previous;
            $start = $days[$index];
            $previous = $days[$index];
        }

        $parts[] = $start === $previous
            ? (string) $start
            : $start.WorkerCsvSchema::DAY_RANGE_SEPARATOR.$previous;

        return implode(WorkerCsvSchema::VALUE_SEPARATOR, $parts);
    }
}
