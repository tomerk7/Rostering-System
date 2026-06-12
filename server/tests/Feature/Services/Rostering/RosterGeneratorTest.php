<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Models\Contract;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Worker;
use App\Services\Rostering\Data\GenerationResult;
use App\Services\Rostering\RosterGenerator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RosterGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private const int YEAR = 2026;

    private const int MONTH = 2; // February 2026 has 28 days.

    private RosterGenerator $generator;

    /**
     * @var array<int, int>
     */
    private array $allShiftIds;

    /**
     * @var array<string, int>
     */
    private array $roleIdByCode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->generator = app(RosterGenerator::class);
        $this->allShiftIds = Shift::query()->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->roleIdByCode = Role::query()->pluck('id', 'code')->map(fn ($id): int => (int) $id)->all();
    }

    public function test_generate_returns_a_deterministic_preview_with_assignments_and_reports(): void
    {
        $this->buildWorkforce(guards: 12, screeners: 6, supervisors: 4);

        $first = $this->generator->generate(self::YEAR, self::MONTH);
        $second = $this->generator->generate(self::YEAR, self::MONTH);

        self::assertInstanceOf(GenerationResult::class, $first);
        self::assertSame(self::YEAR, $first->year);
        self::assertSame(self::MONTH, $first->month);
        self::assertNotEmpty($first->assignments);

        // Same input must produce an identical roster and identical reports.
        self::assertEquals($first->assignments, $second->assignments);
        self::assertEquals($first->coverageShortages, $second->coverageShortages);
        self::assertEquals($first->hoursShortfalls, $second->hoursShortfalls);

        foreach ($first->assignments as $assignment) {
            self::assertSame(AssignmentSource::Auto->value, $assignment['source']);
        }
    }

    public function test_generate_reports_coverage_shortages_when_the_workforce_is_too_small(): void
    {
        // Far fewer workers than the month's 756 positions require.
        $this->buildWorkforce(guards: 3, screeners: 1, supervisors: 1);

        $result = $this->generator->generate(self::YEAR, self::MONTH);

        self::assertTrue($result->hasCoverageShortages());

        foreach ($result->coverageShortages as $shortage) {
            self::assertLessThan($shortage['required'], $shortage['assigned']);
            self::assertArrayHasKey('role_id', $shortage);
            self::assertArrayHasKey('shift_id', $shortage);
        }
    }

    public function test_generate_reports_workers_left_below_minimum_hours(): void
    {
        // A large supervisor pool sharing little demand: each lands well under the
        // 240h (30-shift) minimum, so every one is reported as a shortfall.
        $supervisors = $this->buildWorkforce(guards: 0, screeners: 0, supervisors: 10, minHours: 240, maxHours: 248);

        $result = $this->generator->generate(self::YEAR, self::MONTH);

        self::assertTrue($result->hasHoursShortfalls());
        self::assertCount(count($supervisors), $result->hoursShortfalls);

        foreach ($result->hoursShortfalls as $shortfall) {
            self::assertSame(240, $shortfall['min_hours']);
            self::assertLessThan($shortfall['min_hours'], $shortfall['scheduled_hours']);
        }
    }

    public function test_generate_excludes_inactive_and_contractless_workers(): void
    {
        $active = $this->buildWorkforce(guards: 2, screeners: 0, supervisors: 0)[0];

        $inactive = Worker::factory()->inactive()->create(['role_id' => $this->roleIdByCode['general_guard']]);
        Contract::factory()->for($inactive)->withAvailability([0, 1, 2, 3, 4, 5, 6], $this->allShiftIds)->create();

        // Active but has no contract, so cannot be scheduled.
        $contractless = Worker::factory()->create(['role_id' => $this->roleIdByCode['general_guard']]);

        $result = $this->generator->generate(self::YEAR, self::MONTH);

        $assignedWorkerIds = array_unique(array_column($result->assignments, 'worker_id'));

        self::assertContains($active->israeli_id, $assignedWorkerIds);
        self::assertNotContains($inactive->israeli_id, $assignedWorkerIds);
        self::assertNotContains($contractless->israeli_id, $assignedWorkerIds);
    }

    public function test_generate_excludes_soft_deleted_workers(): void
    {
        $active = $this->buildWorkforce(guards: 2, screeners: 0, supervisors: 0)[0];

        $trashed = Worker::factory()->trashed()->create(['role_id' => $this->roleIdByCode['general_guard']]);
        Contract::factory()->for($trashed)->withAvailability([0, 1, 2, 3, 4, 5, 6], $this->allShiftIds)->create();

        $result = $this->generator->generate(self::YEAR, self::MONTH);

        $assignedWorkerIds = array_unique(array_column($result->assignments, 'worker_id'));

        self::assertContains($active->israeli_id, $assignedWorkerIds);
        self::assertNotContains($trashed->israeli_id, $assignedWorkerIds);
    }

    public function test_generate_with_cost_optimization_is_deterministic_and_reports_match_assignments(): void
    {
        $this->buildWorkforce(guards: 12, screeners: 6, supervisors: 4);

        $first = $this->generator->generate(self::YEAR, self::MONTH, optimizeCost: true);
        $second = $this->generator->generate(self::YEAR, self::MONTH, optimizeCost: true);

        self::assertNotEmpty($first->assignments);
        self::assertEquals($first->assignments, $second->assignments);
        self::assertEquals($first->coverageShortages, $second->coverageShortages);
        self::assertEquals($first->hoursShortfalls, $second->hoursShortfalls);

        // The shortfall report must reflect the post-optimization assignments.
        $scheduledHours = [];

        foreach ($first->assignments as $assignment) {
            $scheduledHours[$assignment['worker_id']] = ($scheduledHours[$assignment['worker_id']] ?? 0) + 8;
        }

        foreach ($first->hoursShortfalls as $shortfall) {
            self::assertSame($scheduledHours[$shortfall['worker_id']] ?? 0, $shortfall['scheduled_hours']);
        }
    }

    public function test_cost_optimization_is_skipped_when_the_roster_is_understaffed(): void
    {
        // Far fewer workers than demand: coverage falls below the optimizer's
        // gate, so the optimized run must equal the plain greedy run while
        // still reporting the shortages.
        $this->buildWorkforce(guards: 3, screeners: 1, supervisors: 1);

        $plain = $this->generator->generate(self::YEAR, self::MONTH);
        $optimized = $this->generator->generate(self::YEAR, self::MONTH, optimizeCost: true);

        self::assertEquals($plain->assignments, $optimized->assignments);
        self::assertEquals($plain->coverageShortages, $optimized->coverageShortages);
        self::assertEquals($plain->hoursShortfalls, $optimized->hoursShortfalls);
        self::assertTrue($optimized->hasCoverageShortages());
    }

    /**
     * Create workers per role, each fully available (all weekdays, all shifts).
     *
     * @return list<Worker> the created workers
     */
    private function buildWorkforce(
        int $guards,
        int $screeners,
        int $supervisors,
        int $minHours = 160,
        int $maxHours = 240,
    ): array {
        $workers = [];

        $counts = [
            'general_guard' => $guards,
            'screener' => $screeners,
            'supervisor' => $supervisors,
        ];

        foreach ($counts as $roleCode => $count) {
            for ($index = 0; $index < $count; $index++) {
                $worker = Worker::factory()->create(['role_id' => $this->roleIdByCode[$roleCode]]);

                Contract::factory()
                    ->for($worker)
                    ->withAvailability([0, 1, 2, 3, 4, 5, 6], $this->allShiftIds)
                    ->create([
                        'min_monthly_hours' => $minHours,
                        'max_monthly_hours' => $maxHours,
                    ]);

                $workers[] = $worker;
            }
        }

        return $workers;
    }

}
