<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AssignmentSource;
use App\Enums\RosterStatus;
use App\Models\Contract;
use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class RosterApiTest extends TestCase
{
    use RefreshDatabase;

    private const int YEAR = 2026;

    private const int MONTH = 6;

    private User $user;

    private Shift $shiftA;

    private Shift $shiftB;

    private Shift $shiftC;

    /**
     * @var array<int, int>
     */
    private array $allShiftIds;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        Sanctum::actingAs(User::factory()->create());

        $this->user = User::query()->firstOrFail();
        $this->shiftA = Shift::query()->where('code', 'A')->firstOrFail();
        $this->shiftB = Shift::query()->where('code', 'B')->firstOrFail();
        $this->shiftC = Shift::query()->where('code', 'C')->firstOrFail();
        $this->allShiftIds = Shift::query()->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        $this->buildWorkforce(guards: 12, screeners: 6, supervisors: 4);
    }

    public function test_roster_preview_returns_alerts_without_persisting(): void
    {
        $response = $this->postJson('/api/rosters/generate', [
            'year' => self::YEAR,
            'month' => self::MONTH,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'year',
                    'month',
                    'assignments',
                    'reports' => [
                        'coverage_shortages',
                        'hours_shortfalls',
                    ],
                    'summary' => [
                        'assignment_count',
                        'coverage_shortage_count',
                        'hours_shortfall_count',
                    ],
                ],
            ])
            ->assertJsonPath('data.year', self::YEAR)
            ->assertJsonPath('data.month', self::MONTH);

        self::assertNull($response->json('data.id'));
        self::assertGreaterThan(0, $response->json('data.summary.assignment_count'));
        $this->assertDatabaseCount('rosters', 0);
        $this->assertDatabaseCount('roster_assignments', 0);
    }

    public function test_roster_can_be_saved_after_preview(): void
    {
        $response = $this->postJson('/api/rosters', [
            'year' => self::YEAR,
            'month' => self::MONTH,
        ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'year',
                    'month',
                    'status',
                    'assignments',
                    'reports' => [
                        'coverage_shortages',
                        'hours_shortfalls',
                    ],
                    'summary' => [
                        'assignment_count',
                        'coverage_shortage_count',
                        'hours_shortfall_count',
                    ],
                ],
            ])
            ->assertJsonPath('data.year', self::YEAR)
            ->assertJsonPath('data.month', self::MONTH)
            ->assertJsonPath('data.status', RosterStatus::Published->value);

        $rosterId = $response->json('data.id');

        self::assertGreaterThan(0, $response->json('data.summary.assignment_count'));
        $this->assertDatabaseHas('rosters', [
            'id' => $rosterId,
            'status' => RosterStatus::Published->value,
            'created_by' => $this->user->id,
        ]);
        $this->assertDatabaseHas('roster_assignments', ['roster_id' => $rosterId]);
    }

    public function test_saving_replaces_the_existing_roster_for_the_month(): void
    {
        $first = $this->postJson('/api/rosters', ['year' => self::YEAR, 'month' => self::MONTH])
            ->assertStatus(201)
            ->json('data.id');

        $second = $this->postJson('/api/rosters', ['year' => self::YEAR, 'month' => self::MONTH])
            ->assertStatus(201)
            ->json('data.id');

        self::assertNotSame($first, $second);
        $this->assertDatabaseMissing('rosters', ['id' => $first]);
        self::assertSame(1, Roster::query()->forPeriod(self::YEAR, self::MONTH)->count());
    }

    public function test_roster_preview_validates_month(): void
    {
        $this->postJson('/api/rosters/generate', ['year' => self::YEAR, 'month' => 13])
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');
    }

    public function test_roster_save_validates_month(): void
    {
        $this->postJson('/api/rosters', ['year' => self::YEAR, 'month' => 13])
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');
    }

    public function test_rosters_can_be_listed_with_assignment_counts(): void
    {
        $roster = $this->createRosterWithAssignment();

        $this->getJson('/api/rosters')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.id', $roster->id)
            ->assertJsonPath('data.0.assignments_count', 1);
    }

    public function test_roster_can_be_shown_with_enriched_assignments_and_filters(): void
    {
        $roster = $this->createRosterWithAssignment();

        $response = $this->getJson("/api/rosters/{$roster->id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $roster->id)
            ->assertJsonPath('data.assignments.0.shift_code', 'A');

        self::assertNotEmpty($response->json('data.assignments.0.worker_name'));

        $this->getJson("/api/rosters/{$roster->id}?date=2026-06-01&shift_id={$this->shiftA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.assignments');

        $this->getJson("/api/rosters/{$roster->id}?shift_id={$this->shiftB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.assignments');
    }

    public function test_manual_assignment_can_be_created_on_a_draft_roster(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $worker = $this->assignableWorker();

        $response = $this->postJson("/api/rosters/{$roster->id}/assignments", [
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-01',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $roster->id)
            ->assertJsonPath('data.assignments_count', 1)
            ->assertJsonPath('data.assignments.0.worker_id', $worker->israeli_id)
            ->assertJsonPath('data.assignments.0.shift_code', 'A')
            ->assertJsonPath('data.assignments.0.source', AssignmentSource::Manual->value);

        $this->assertDatabaseHas('roster_assignments', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-01 00:00:00',
            'source' => AssignmentSource::Manual->value,
        ]);
    }

    public function test_manual_assignment_worker_can_be_changed_on_a_draft_roster(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $original = $this->assignableWorker();

        $assignment = RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $original->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-04',
            'source' => AssignmentSource::Auto,
        ]);

        $replacement = Worker::query()
            ->active()
            ->whereHas('contract')
            ->whereKeyNot($original->israeli_id)
            ->where('role_id', $original->role_id)
            ->firstOrFail();

        $this->putJson("/api/rosters/{$roster->id}/assignments/{$assignment->id}", [
            'worker_id' => $replacement->israeli_id,
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.assignments.0.worker_id', $replacement->israeli_id)
            ->assertJsonPath('data.assignments.0.source', AssignmentSource::Manual->value);

        $this->assertDatabaseHas('roster_assignments', [
            'id' => $assignment->id,
            'worker_id' => $replacement->israeli_id,
            'source' => AssignmentSource::Manual->value,
        ]);
    }

    public function test_manual_assignment_can_be_deleted_from_a_draft_roster(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $worker = $this->assignableWorker();

        $assignment = RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-02',
            'source' => AssignmentSource::Manual,
        ]);

        $this->deleteJson("/api/rosters/{$roster->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('roster_assignments', ['id' => $assignment->id]);
    }

    public function test_manual_assignment_rejects_a_third_shift_on_the_same_day(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $worker = $this->assignableWorker();

        foreach ([$this->shiftA, $this->shiftB] as $shift) {
            RosterAssignment::query()->create([
                'roster_id' => $roster->id,
                'worker_id' => $worker->israeli_id,
                'shift_id' => $shift->id,
                'work_date' => '2026-06-03',
                'source' => AssignmentSource::Auto,
            ]);
        }

        $this->postJson("/api/rosters/{$roster->id}/assignments", [
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->shiftC->id,
            'work_date' => '2026-06-03',
        ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'A worker may take at most two shifts per calendar day.');

        $this->assertDatabaseCount('roster_assignments', 2);
    }

    public function test_roster_can_be_deleted_with_its_assignments(): void
    {
        $roster = $this->createRosterWithAssignment();

        $this->deleteJson("/api/rosters/{$roster->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('rosters', ['id' => $roster->id]);
        $this->assertDatabaseMissing('roster_assignments', ['roster_id' => $roster->id]);
    }

    private function createRosterWithAssignment(): Roster
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $this->assignableWorker()->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-01',
            'source' => AssignmentSource::Auto,
        ]);

        return $roster;
    }

    private function assignableWorker(): Worker
    {
        $worker = Worker::query()->active()->whereHas('contract')->firstOrFail();

        $worker->contract()->update(['max_monthly_hours' => 240]);

        return $worker->fresh(['contract.availability']);
    }

    /**
     * Create fully-available workers per role.
     *
     * @return list<Worker>
     */
    private function buildWorkforce(int $guards, int $screeners, int $supervisors): array
    {
        $roleIdByCode = Role::query()->pluck('id', 'code')->map(fn (mixed $id): int => (int) $id)->all();
        $workers = [];

        foreach ([
            'general_guard' => $guards,
            'screener' => $screeners,
            'supervisor' => $supervisors,
        ] as $roleCode => $count) {
            for ($index = 0; $index < $count; $index++) {
                $worker = Worker::factory()->create(['role_id' => $roleIdByCode[$roleCode]]);

                Contract::factory()
                    ->for($worker)
                    ->withAvailability([0, 1, 2, 3, 4, 5, 6], $this->allShiftIds)
                    ->create([
                        'min_monthly_hours' => 160,
                        'max_monthly_hours' => 240,
                    ]);

                $workers[] = $worker;
            }
        }

        return $workers;
    }
}
