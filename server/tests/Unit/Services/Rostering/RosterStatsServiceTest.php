<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Models\Contract;
use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\Worker;
use App\Services\Rostering\RosterStatsService;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RosterStatsServiceTest extends TestCase
{
    use RefreshDatabase;

    private RosterStatsService $service;

    private Roster $roster;

    private Shift $shiftA;

    private Shift $shiftB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->service = app(RosterStatsService::class);
        $this->roster = Roster::factory()->forPeriod(2026, 6)->create();
        $this->shiftA = Shift::query()->where('code', 'A')->firstOrFail();
        $this->shiftB = Shift::query()->where('code', 'B')->firstOrFail();
    }

    public function test_rows_aggregate_hours_and_snapshot_cost_per_worker(): void
    {
        $worker = $this->workerWithContract(hourlyCost: 40, minHours: 160, maxHours: 240);

        $this->assign($worker, $this->shiftA, '2026-06-01', hourlyCost: 40);
        $this->assign($worker, $this->shiftB, '2026-06-01', hourlyCost: 40);
        $this->assign($worker, $this->shiftA, '2026-06-02', hourlyCost: 40);

        $result = $this->service->forRoster($this->roster);
        $row = $result->rows[0];

        self::assertCount(1, $result->rows);
        self::assertSame($worker->israeli_id, $row->workerId);
        self::assertSame($worker->full_name, $row->name);
        self::assertSame(24, $row->actualHours);
        self::assertSame(960.0, $row->totalCost);
        self::assertSame(160, $row->minHours);
        self::assertSame(240, $row->maxHours);
        self::assertSame(136, $row->shortfallHours);
    }

    public function test_contract_rate_change_does_not_rewrite_cost_but_updates_targets(): void
    {
        $worker = $this->workerWithContract(hourlyCost: 40, minHours: 160, maxHours: 240);

        $this->assign($worker, $this->shiftA, '2026-06-01', hourlyCost: 40);

        $worker->contract()->update([
            'hourly_cost' => 90,
            'min_monthly_hours' => 8,
            'max_monthly_hours' => 16,
        ]);

        $row = $this->service->forRoster($this->roster)->rows[0];

        self::assertSame(320.0, $row->totalCost);
        self::assertSame(8, $row->minHours);
        self::assertSame(16, $row->maxHours);
        self::assertSame(0, $row->shortfallHours);
    }

    public function test_worker_without_contract_gets_zero_targets_and_snapshot_cost(): void
    {
        $worker = Worker::factory()->create();

        $this->assign($worker, $this->shiftA, '2026-06-01', hourlyCost: 75);

        $row = $this->service->forRoster($this->roster)->rows[0];

        self::assertSame(0, $row->minHours);
        self::assertSame(0, $row->maxHours);
        self::assertSame(600.0, $row->totalCost);
        self::assertSame(0, $row->shortfallHours);
    }

    public function test_rows_include_the_worker_role_name(): void
    {
        $supervisorRoleId = (int) Role::query()->where('code', 'supervisor')->value('id');
        $worker = $this->workerWithContract(hourlyCost: 40, minHours: 0, maxHours: 240, roleId: $supervisorRoleId);

        $this->assign($worker, $this->shiftA, '2026-06-01', hourlyCost: 40);

        $row = $this->service->forRoster($this->roster)->rows[0];

        $expectedName = (string) Role::query()->whereKey($supervisorRoleId)->value('name');
        self::assertSame($expectedName, $row->role);
    }

    public function test_summary_totals_shortfall_count_and_leaderboards(): void
    {
        $workers = [];

        foreach ([30, 40, 50, 60, 70, 80] as $rate) {
            $worker = $this->workerWithContract(hourlyCost: $rate, minHours: 16, maxHours: 240);
            $this->assign($worker, $this->shiftA, '2026-06-01', hourlyCost: $rate);
            $workers[$rate] = $worker;
        }

        // One worker gets a second shift, satisfying their 16h minimum.
        $this->assign($workers[80], $this->shiftB, '2026-06-01', hourlyCost: 80);

        $summary = $this->service->forRoster($this->roster)->summary;

        // 5 x 8h + 1 x 16h = 56h; cost = 8*(30+40+50+60+70) + 16*80 = 2000 + 1280
        self::assertSame(56, $summary['total_hours']);
        self::assertSame(3280.0, $summary['total_cost']);
        self::assertSame(5, $summary['workers_with_shortfall']);

        $leaderboards = $summary['leaderboards'];
        self::assertCount(5, $leaderboards['highest_paid']);
        self::assertCount(5, $leaderboards['lowest_paid']);
        self::assertSame($workers[80]->israeli_id, $leaderboards['highest_paid'][0]['worker_id']);
        self::assertSame(1280.0, $leaderboards['highest_paid'][0]['total_cost']);
        self::assertSame($workers[30]->israeli_id, $leaderboards['lowest_paid'][0]['worker_id']);
        self::assertSame($workers[80]->israeli_id, $leaderboards['most_hours'][0]['worker_id']);
        self::assertSame(16, $leaderboards['most_hours'][0]['actual_hours']);
        self::assertSame(8, $leaderboards['fewest_hours'][0]['actual_hours']);
    }

    public function test_empty_roster_returns_empty_rows_and_zero_summary(): void
    {
        $result = $this->service->forRoster($this->roster);

        self::assertSame([], $result->rows);
        self::assertSame(0.0, $result->summary['total_cost']);
        self::assertSame(0, $result->summary['total_hours']);
        self::assertSame(0, $result->summary['workers_with_shortfall']);
        self::assertSame([], $result->summary['leaderboards']['highest_paid']);
    }

    public function test_to_array_serializes_rows_with_snake_case_keys(): void
    {
        $worker = $this->workerWithContract(hourlyCost: 40, minHours: 16, maxHours: 240);
        $this->assign($worker, $this->shiftA, '2026-06-01', hourlyCost: 40);

        $payload = $this->service->forRoster($this->roster)->toArray();

        self::assertSame($worker->israeli_id, $payload['rows'][0]['worker_id']);
        self::assertSame(8, $payload['rows'][0]['actual_hours']);
        self::assertSame(8, $payload['summary']['total_hours']);
    }

    /**
     * Create an active worker with a contract.
     */
    private function workerWithContract(float $hourlyCost, int $minHours, int $maxHours, ?int $roleId = null): Worker
    {
        $worker = Worker::factory()->create($roleId !== null ? ['role_id' => $roleId] : []);

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
    private function assign(Worker $worker, Shift $shift, string $workDate, float $hourlyCost): void
    {
        RosterAssignment::query()->create([
            'roster_id' => $this->roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $shift->id,
            'work_date' => $workDate,
            'source' => AssignmentSource::Auto,
            'hourly_cost' => $hourlyCost,
        ]);
    }
}
