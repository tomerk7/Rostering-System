<?php

declare(strict_types=1);

namespace App\Services\Workers\Csv;

use App\Data\Worker;
use App\Repositories\WorkerRepository;
use App\Support\RoleCode;

/**
 * Build the worker CSV (header + one row per worker).
 */
class WorkerCsvExporter
{
    /**
     * Constructor.
     *
     * @param WorkerCsvSchema $schema
     * @param WorkerRepository $workers
     */
    public function __construct(
        private WorkerCsvSchema $schema = new WorkerCsvSchema,
        private WorkerRepository $workers = new WorkerRepository,
    ) {}

    /**
     * Full CSV header row: fixed columns plus one column per shift.
     *
     * @return list<string>
     */
    public function headers(): array
    {
        $shiftLabels = array_map(
            static fn (array $shift): string => WorkerCsvSchema::shiftColumnLabel($shift),
            $this->schema->orderedShifts(),
        );

        return array_merge(WorkerCsvSchema::FIXED_HEADERS, $shiftLabels);
    }

    /**
     * Render all workers to a CSV string (header + rows), ordered by israeli_id.
     *
     * @return string
     */
    public function toString(): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $this->headers());

        foreach ($this->workers->allForExport() as $worker) {
            fputcsv($handle, $this->toRow($worker));
        }

        rewind($handle);
        $content = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $content;
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
                if ($slot->shift !== null) {
                    $daysByShiftCode[$slot->shift->code][] = $slot->dayOfWeek;
                }
            }
        }

        $row = [
            $worker->fullName,
            $worker->israeliId,
            $worker->role === null ? '' : RoleCode::labelForCode($worker->role->code),
            $worker->isActive ? WorkerCsvSchema::STATUS_ACTIVE : WorkerCsvSchema::STATUS_INACTIVE,
            (string) ($contract?->hourlyCost ?? ''),
            (string) ($contract?->minMonthlyHours ?? ''),
            (string) ($contract?->maxMonthlyHours ?? ''),
        ];

        foreach ($this->schema->orderedShifts() as $shift) {
            $row[] = $this->compressDays($daysByShiftCode[$shift['code']] ?? []);
        }

        return $row;
    }

    /**
     * Compress sorted day-of-week numbers (0-6) into a cron-style expression
     * (CSV days 1-7).
     *
     * @param list<int> $days
     * @return string
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

            $parts[] = $start === $previous ? (string) $start : $start . WorkerCsvSchema::DAY_RANGE_SEPARATOR . $previous;
            $start = $days[$index];
            $previous = $days[$index];
        }

        $parts[] = $start === $previous ? (string) $start : $start . WorkerCsvSchema::DAY_RANGE_SEPARATOR . $previous;

        return implode(WorkerCsvSchema::VALUE_SEPARATOR, $parts);
    }
}
