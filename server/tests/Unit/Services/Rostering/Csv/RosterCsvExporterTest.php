<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering\Csv;

use App\Enums\AssignmentSource;
use App\Models\Contract;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\Worker;
use App\Services\Rostering\Csv\RosterCsvExporter;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RosterCsvExporterTest extends TestCase
{
    use RefreshDatabase;

    private RosterCsvExporter $exporter;

    private Roster $roster;

    private Shift $shiftA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->exporter = app(RosterCsvExporter::class);
        $this->roster = Roster::factory()->forPeriod(2026, 6)->create();
        $this->shiftA = Shift::query()->where('code', 'A')->firstOrFail();
    }

    public function test_headers_keep_the_existing_column_order(): void
    {
        self::assertSame([
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
        ], $this->exporter->headers());
    }

    public function test_rows_are_formatted_with_two_decimal_strings(): void
    {
        $worker = $this->workerWithContract(hourlyCost: 41.5, minHours: 160, maxHours: 240);
        $this->assign($worker, '2026-06-01', hourlyCost: 41.5);
        $this->assign($worker, '2026-06-02', hourlyCost: 41.5);

        $rows = $this->export();

        self::assertCount(2, $rows);
        self::assertSame(RosterCsvExporter::HEADERS, $rows[0]);
        self::assertSame([
            $worker->israeli_id,
            $worker->full_name,
            '2026',
            '6',
            '160',
            '240',
            '16',
            '6.67',   // 16 / 240, uncapped
            '10.00',  // 16 / 160, capped at 100
            '664.00', // 16h x 41.50
        ], $rows[1]);
    }

    public function test_contract_rate_change_does_not_change_exported_cost(): void
    {
        $worker = $this->workerWithContract(hourlyCost: 40, minHours: 160, maxHours: 240);
        $this->assign($worker, '2026-06-01', hourlyCost: 40);

        $worker->contract()->update(['hourly_cost' => 99]);

        $rows = $this->export();

        self::assertSame('320.00', $rows[1][9]);
    }

    /**
     * Run the exporter against an in-memory handle and parse it back.
     *
     * @return list<list<string>>
     */
    private function export(): array
    {
        $handle = fopen('php://temp', 'r+');
        $this->exporter->writeTo($handle, $this->roster->fresh());
        rewind($handle);

        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Create an active worker with a contract.
     */
    private function workerWithContract(float $hourlyCost, int $minHours, int $maxHours): Worker
    {
        $worker = Worker::factory()->create();

        Contract::factory()->for($worker)->create([
            'hourly_cost' => $hourlyCost,
            'min_monthly_hours' => $minHours,
            'max_monthly_hours' => $maxHours,
        ]);

        return $worker;
    }

    /**
     * Insert an assignment with an explicit snapshot rate.
     */
    private function assign(Worker $worker, string $workDate, float $hourlyCost): void
    {
        RosterAssignment::query()->create([
            'roster_id' => $this->roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => $workDate,
            'source' => AssignmentSource::Auto,
            'hourly_cost' => $hourlyCost,
        ]);
    }
}
