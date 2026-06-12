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
