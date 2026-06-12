<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AssignmentSource;
use App\Models\Contract;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RosterStatsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);
    }

    public function test_stats_require_authentication(): void
    {
        $roster = Roster::factory()->forPeriod(2026, 6)->create();

        $this->getJson("/api/rosters/{$roster->id}/stats")
            ->assertUnauthorized();
    }

    public function test_stats_return_404_for_missing_roster(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/rosters/999999/stats')
            ->assertNotFound();
    }

    public function test_stats_return_rows_summary_and_leaderboards(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $roster = Roster::factory()->forPeriod(2026, 6)->create();
        $shiftA = Shift::query()->where('code', 'A')->firstOrFail();

        $worker = Worker::factory()->create();
        Contract::factory()->for($worker)->create([
            'hourly_cost' => 40,
            'min_monthly_hours' => 16,
            'max_monthly_hours' => 240,
        ]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $shiftA->id,
            'work_date' => '2026-06-01',
            'source' => AssignmentSource::Auto,
            'hourly_cost' => 40,
        ]);

        $this->getJson("/api/rosters/{$roster->id}/stats")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'rows' => [
                        '*' => [
                            'worker_id',
                            'name',
                            'role',
                            'min_hours',
                            'max_hours',
                            'actual_hours',
                            'percent_of_min',
                            'percent_of_max',
                            'total_cost',
                            'shortfall_hours',
                        ],
                    ],
                    'summary' => [
                        'total_cost',
                        'total_hours',
                        'workers_with_shortfall',
                        'leaderboards' => [
                            'highest_paid',
                            'lowest_paid',
                            'most_hours',
                            'fewest_hours',
                        ],
                    ],
                ],
            ])
            ->assertJsonPath('data.rows.0.worker_id', $worker->israeli_id)
            ->assertJsonPath('data.rows.0.actual_hours', 8)
            ->assertJsonPath('data.rows.0.total_cost', 320)
            ->assertJsonPath('data.rows.0.shortfall_hours', 8)
            ->assertJsonPath('data.summary.total_hours', 8)
            ->assertJsonPath('data.summary.workers_with_shortfall', 1)
            ->assertJsonPath('data.summary.leaderboards.highest_paid.0.worker_id', $worker->israeli_id);
    }
}
