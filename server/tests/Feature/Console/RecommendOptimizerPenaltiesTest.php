<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Contract;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class RecommendOptimizerPenaltiesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_it_recommends_penalties_from_co_eligible_wage_differences(): void
    {
        $guardRoleId = (int) Role::query()->where('code', 'general_guard')->value('id');
        $shiftIds = Shift::query()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $this->workerWithContract($guardRoleId, 40.0, $shiftIds);
        $this->workerWithContract($guardRoleId, 80.0, $shiftIds);
        $this->workerWithContract($guardRoleId, 200.0, $shiftIds, active: false);

        $exitCode = Artisan::call('roster:recommend-penalties', [
            '--year' => 2026,
            '--month' => 6,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(0, $exitCode);
        self::assertSame(2, $report['context']['active_contracts']);
        self::assertSame(1, $report['context']['co_eligible_worker_pairs']);
        self::assertSame(40.0, $report['wage_analysis']['p75_co_eligible_hourly_difference']);
        self::assertSame(40.0, $report['wage_analysis']['maximum_co_eligible_hourly_difference']);
        self::assertSame(45.0, $report['recommended']['shortfall_penalty_per_hour']);
        self::assertSame([
            'maximum_savings' => 0.0,
            'cost_focused' => 40.0,
            'balanced' => 80.0,
            'distribution_focused' => 160.0,
        ], $report['recommended']['balance_weights']);
    }

    public function test_it_reports_capacity_problems_that_penalties_cannot_solve(): void
    {
        $supervisorRoleId = (int) Role::query()->where('code', 'supervisor')->value('id');
        $shiftIds = Shift::query()->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();

        $this->workerWithContract(
            $supervisorRoleId,
            70.0,
            $shiftIds,
            minHours: 800,
            maxHours: 800,
        );

        Artisan::call('roster:recommend-penalties', [
            '--year' => 2026,
            '--month' => 6,
            '--json' => true,
        ]);
        $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $supervisor = collect($report['capacity'])->firstWhere('role', 'Supervisor');

        self::assertSame('minimum_shortfall_unavoidable', $supervisor['status']);
        self::assertStringContainsString('penalties cannot eliminate all shortfalls', $supervisor['message']);
    }

    public function test_it_rejects_invalid_calibration_options(): void
    {
        $this->artisan('roster:recommend-penalties', ['--month' => 13])
            ->expectsOutputToContain('The month must be between 1 and 12.')
            ->assertExitCode(2);
    }

    /**
     * @param  list<int>  $shiftIds
     */
    private function workerWithContract(
        int $roleId,
        float $hourlyCost,
        array $shiftIds,
        int $minHours = 160,
        int $maxHours = 240,
        bool $active = true,
    ): void {
        $worker = Worker::factory()->create([
            'role_id' => $roleId,
            'is_active' => $active,
        ]);

        Contract::factory()
            ->for($worker)
            ->withAvailability(range(0, 6), $shiftIds)
            ->create([
                'hourly_cost' => $hourlyCost,
                'min_monthly_hours' => $minHours,
                'max_monthly_hours' => $maxHours,
            ]);
    }
}
