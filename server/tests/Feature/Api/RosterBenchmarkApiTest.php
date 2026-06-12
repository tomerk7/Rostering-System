<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Contract;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RosterBenchmarkApiTest extends TestCase
{
    use RefreshDatabase;

    private const int MONTH = 6;

    private const array METRIC_KEYS = [
        'assignments',
        'coverage_shortages',
        'total_cost',
        'min_hours_shortfall_workers',
        'min_hours_shortfall_hours',
        'max_hours_violations',
        'hours_std_dev',
        'generation_seconds',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        Sanctum::actingAs(User::factory()->create());
    }

    public function test_benchmark_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/rosters/benchmark', ['month' => self::MONTH])
            ->assertStatus(401);
    }

    public function test_benchmark_validates_month(): void
    {
        $this->postJson('/api/rosters/benchmark', ['month' => 13])
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');

        $this->postJson('/api/rosters/benchmark', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');
    }

    public function test_benchmark_fails_without_contracts(): void
    {
        $this->postJson('/api/rosters/benchmark', ['month' => self::MONTH])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'No contracts found — add some workers first.');
    }

    public function test_benchmark_compares_plain_and_optimized_runs(): void
    {
        $this->buildWorkforce(guards: 12, screeners: 6, supervisors: 4);

        $response = $this->postJson('/api/rosters/benchmark', ['month' => self::MONTH])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'year',
                    'month',
                    'plain' => self::METRIC_KEYS,
                    'optimized' => self::METRIC_KEYS,
                    'saved_amount',
                    'saved_percent',
                    'assignments_match',
                    'worker_stats' => [
                        'plain',
                        'optimized',
                        'deltas',
                        'leaderboards' => ['plain', 'optimized'],
                        'truncated',
                    ],
                ],
            ])
            ->assertJsonPath('data.year', (int) now()->year)
            ->assertJsonPath('data.month', self::MONTH)
            ->assertJsonPath('data.assignments_match', true);

        $plain = $response->json('data.plain');
        $optimized = $response->json('data.optimized');

        self::assertGreaterThan(0, $plain['assignments']);
        self::assertSame($plain['assignments'], $optimized['assignments']);
        self::assertEqualsWithDelta(
            $plain['total_cost'] - $optimized['total_cost'],
            $response->json('data.saved_amount'),
            0.01,
        );
        $this->assertDatabaseCount('rosters', 0);
        $this->assertDatabaseCount('roster_assignments', 0);
    }

    public function test_benchmark_returns_per_worker_stats_and_deltas(): void
    {
        $this->buildWorkforce(guards: 12, screeners: 6, supervisors: 4);

        $response = $this->postJson('/api/rosters/benchmark', ['month' => self::MONTH])
            ->assertStatus(200)
            ->assertJsonPath('data.worker_stats.truncated', false);

        $workerStats = $response->json('data.worker_stats');

        // Row field names match the roster stats endpoint so the frontend
        // grid component is reusable across both screens.
        $expectedKeys = [
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
        ];

        self::assertNotEmpty($workerStats['plain']);
        self::assertNotEmpty($workerStats['optimized']);

        foreach (['plain', 'optimized'] as $variant) {
            self::assertSame($expectedKeys, array_keys($workerStats[$variant][0]));

            $row = $workerStats[$variant][0];
            self::assertEqualsWithDelta($row['actual_hours'] * $this->contractRate($row['worker_id']), $row['total_cost'], 0.01);

            $leaderboards = $workerStats['leaderboards'][$variant];
            self::assertArrayHasKey('highest_paid', $leaderboards);
            self::assertArrayHasKey('most_hours', $leaderboards);
            self::assertLessThanOrEqual(5, count($leaderboards['highest_paid']));
        }

        // Per-variant aggregate cost must equal the sum of its worker rows.
        self::assertEqualsWithDelta(
            $response->json('data.plain.total_cost'),
            array_sum(array_column($workerStats['plain'], 'total_cost')),
            0.01,
        );

        foreach ($workerStats['deltas'] as $delta) {
            self::assertSame($delta['optimized_hours'] - $delta['plain_hours'], $delta['hours_delta']);
            self::assertEqualsWithDelta($delta['optimized_cost'] - $delta['plain_cost'], $delta['cost_delta'], 0.01);
            self::assertTrue(
                $delta['hours_delta'] !== 0
                || abs($delta['cost_delta']) > 0
                || $delta['shortfall_change'] !== null,
                'Deltas must only contain workers whose stats changed.',
            );
        }
    }

    /**
     * Current contract rate for a worker.
     */
    private function contractRate(string $workerId): float
    {
        return (float) Contract::query()->where('worker_id', $workerId)->value('hourly_cost');
    }

    /**
     * Create fully-available workers per role.
     */
    private function buildWorkforce(int $guards, int $screeners, int $supervisors): void
    {
        $roleIdByCode = Role::query()->pluck('id', 'code')->map(fn (mixed $id): int => (int) $id)->all();
        $allShiftIds = Shift::query()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        foreach ([
            'general_guard' => $guards,
            'screener' => $screeners,
            'supervisor' => $supervisors,
        ] as $roleCode => $count) {
            for ($index = 0; $index < $count; $index++) {
                $worker = Worker::factory()->create(['role_id' => $roleIdByCode[$roleCode]]);

                Contract::factory()
                    ->for($worker)
                    ->withAvailability([0, 1, 2, 3, 4, 5, 6], $allShiftIds)
                    ->create([
                        'min_monthly_hours' => 160,
                        'max_monthly_hours' => 240,
                    ]);
            }
        }
    }
}
