<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\AssignmentSource;
use App\Enums\RosterAlertType;
use App\Jobs\GenerateRosterJob;
use App\Models\Contract;
use App\Models\CoverageShortage;
use App\Models\Role;
use App\Models\Roster;
use App\Models\RosterAlert;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
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

    public function test_roster_generation_validates_month(): void
    {
        $this->postJson('/api/rosters', ['month' => 13])
            ->assertStatus(422)
            ->assertJsonValidationErrors('month');
    }

    public function test_roster_can_be_generated_and_persisted(): void
    {
        $currentYear = (int) now()->year;

        $response = $this->postJson('/api/rosters', [
            'month' => self::MONTH,
        ])
            ->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'id',
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
            ->assertJsonPath('data.year', $currentYear)
            ->assertJsonPath('data.month', self::MONTH);

        $rosterId = $response->json('data.id');
        $coverageAlertCount = count($response->json('data.reports.coverage_shortages'));
        $hoursAlertCount = count($response->json('data.reports.hours_shortfalls'));

        self::assertGreaterThan(0, $response->json('data.summary.assignment_count'));
        self::assertGreaterThan(0, $coverageAlertCount + $hoursAlertCount);
        $this->assertDatabaseHas('rosters', [
            'id' => $rosterId,
            'created_by' => $this->user->id,
        ]);
        $this->assertDatabaseHas('roster_assignments', ['roster_id' => $rosterId]);
        self::assertSame(
            $coverageAlertCount,
            CoverageShortage::query()
                ->where('roster_id', $rosterId)
                ->count(),
        );
        self::assertSame(
            $hoursAlertCount,
            RosterAlert::query()
                ->where('roster_id', $rosterId)
                ->hoursShortfall()
                ->count(),
        );
    }

    public function test_roster_generation_is_queued_when_the_queue_is_asynchronous(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/rosters', [
            'month' => self::MONTH,
        ])
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'processing');

        $rosterId = (int) $response->json('data.id');

        self::assertGreaterThan(0, $rosterId);
        Queue::assertPushed(GenerateRosterJob::class, 1);
        $this->assertDatabaseHas('rosters', [
            'id' => $rosterId,
            'status' => 'processing',
        ]);

        $this->getJson("/api/rosters/{$rosterId}")
            ->assertOk()
            ->assertJsonPath('data.id', $rosterId)
            ->assertJsonPath('data.status', 'processing');
    }

    public function test_generating_replaces_the_existing_roster_for_the_month(): void
    {
        $first = $this->postJson('/api/rosters', ['month' => self::MONTH])
            ->assertStatus(201)
            ->json('data.id');

        $second = $this->postJson('/api/rosters', ['month' => self::MONTH])
            ->assertStatus(201)
            ->json('data.id');

        self::assertNotSame($first, $second);
        $this->assertDatabaseMissing('rosters', ['id' => $first]);
        self::assertSame(1, Roster::query()->forPeriod((int) now()->year, self::MONTH)->count());
    }

    public function test_regenerating_keeps_the_same_roster_id_and_replaces_assignments(): void
    {
        $roster = $this->createRosterWithAssignment();
        $manualAssignmentId = RosterAssignment::query()
            ->where('roster_id', $roster->id)
            ->value('id');
        $staleShortage = CoverageShortage::query()->create([
            'roster_id' => $roster->id,
            'work_date' => '2026-06-01',
            'shift_id' => $this->shiftA->id,
            'role_id' => Role::query()->value('id'),
            'required_count' => 99,
            'assigned_count' => 0,
        ]);

        $response = $this->postJson("/api/rosters/{$roster->id}/regenerate")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $roster->id)
            ->assertJsonPath('data.year', self::YEAR)
            ->assertJsonPath('data.month', self::MONTH);

        self::assertGreaterThan(0, $response->json('data.summary.assignment_count'));
        $this->assertDatabaseHas('rosters', ['id' => $roster->id]);
        $this->assertDatabaseMissing('roster_assignments', ['id' => $manualAssignmentId]);
        $this->assertDatabaseMissing('coverage_shortages', ['id' => $staleShortage->id]);
        $this->assertDatabaseHas('roster_assignments', ['roster_id' => $roster->id]);
        self::assertSame(1, Roster::query()->forPeriod(self::YEAR, self::MONTH)->count());
        self::assertSame(
            count($response->json('data.reports.coverage_shortages')),
            CoverageShortage::query()->where('roster_id', $roster->id)->count(),
        );
        self::assertSame(
            count($response->json('data.reports.hours_shortfalls')),
            RosterAlert::query()->where('roster_id', $roster->id)->count(),
        );
    }

    public function test_roster_regeneration_is_queued(): void
    {
        Queue::fake();

        $roster = $this->createRosterWithAssignment();

        $this->postJson("/api/rosters/{$roster->id}/regenerate")
            ->assertStatus(202)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $roster->id)
            ->assertJsonPath('data.status', 'processing');

        Queue::assertPushed(GenerateRosterJob::class, 1);
        $this->assertDatabaseHas('rosters', [
            'id' => $roster->id,
            'status' => 'processing',
        ]);
        $this->assertDatabaseCount('roster_assignments', 1);
    }

    public function test_rosters_can_be_listed_with_assignment_counts(): void
    {
        $roster = $this->createRosterWithAssignment();

        $this->getJson('/api/rosters')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.current_year', (int) now()->year)
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

    public function test_roster_assignments_can_be_fetched_by_date_range(): void
    {
        $roster = $this->createRosterWithAssignment();
        $firstAssignment = $roster->assignments()->firstOrFail();

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $firstAssignment->worker_id,
            'shift_id' => $this->shiftB->id,
            'work_date' => '2026-06-10',
            'source' => AssignmentSource::Auto,
        ]);

        $this->getJson(
            "/api/rosters/{$roster->id}/assignments?from_date=2026-06-01&to_date=2026-06-07",
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.work_date', '2026-06-01')
            ->assertJsonPath('meta.from_date', '2026-06-01')
            ->assertJsonPath('meta.to_date', '2026-06-07')
            ->assertJsonPath(
                "meta.assigned_hours_by_worker.{$firstAssignment->worker_id}",
                16,
            );

        $this->getJson(
            "/api/rosters/{$roster->id}/assignments?from_date=2026-05-31&to_date=2026-06-06",
        )
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_roster_reports_are_loaded_from_persisted_alerts(): void
    {
        $roster = $this->createRosterWithAssignment();
        $worker = $this->assignableWorker();
        $role = Role::query()->findOrFail($worker->role_id);

        CoverageShortage::query()->create([
            'roster_id' => $roster->id,
            'work_date' => '2026-06-02',
            'shift_id' => $this->shiftA->id,
            'role_id' => $role->id,
            'required_count' => 4,
            'assigned_count' => 2,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 160,
            'scheduled_hours' => 120,
        ]);

        $this->getJson("/api/rosters/{$roster->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.reports.coverage_shortages')
            ->assertJsonCount(1, 'data.reports.hours_shortfalls')
            ->assertJsonPath('data.reports.coverage_shortages.0.work_date', '2026-06-02')
            ->assertJsonPath('data.reports.coverage_shortages.0.shift_code', 'A')
            ->assertJsonPath('data.reports.coverage_shortages.0.role_name', $role->name)
            ->assertJsonPath('data.reports.coverage_shortages.0.missing', 2)
            ->assertJsonPath('data.reports.hours_shortfalls.0.worker_id', $worker->israeli_id)
            ->assertJsonPath('data.reports.hours_shortfalls.0.worker_name', $worker->full_name)
            ->assertJsonPath('data.reports.hours_shortfalls.0.shortfall_hours', 40)
            ->assertJsonPath('data.summary.coverage_shortage_count', 1)
            ->assertJsonPath('data.summary.hours_shortfall_count', 1);
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

    public function test_manual_add_clears_coverage_shortage_when_last_slot_is_filled(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $supervisor = $this->supervisorWorker();
        $supervisorRole = Role::query()->where('code', 'supervisor')->firstOrFail();
        $shiftAId = $this->shiftA->id;

        CoverageShortage::query()->create([
            'roster_id' => $roster->id,
            'work_date' => '2026-06-01',
            'shift_id' => $this->shiftA->id,
            'role_id' => $supervisorRole->id,
            'required_count' => 1,
            'assigned_count' => 0,
        ]);

        $response = $this->postJson("/api/rosters/{$roster->id}/assignments", [
            'worker_id' => $supervisor->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-01',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $matchingShortages = collect($response->json('data.reports.coverage_shortages'))
            ->filter(static fn (array $shortage): bool => $shortage['work_date'] === '2026-06-01'
                && (int) $shortage['shift_id'] === $shiftAId
                && (int) $shortage['role_id'] === $supervisorRole->id);

        self::assertCount(0, $matchingShortages);

        $this->assertDatabaseMissing('coverage_shortages', [
            'roster_id' => $roster->id,
            'work_date' => '2026-06-01 00:00:00',
            'shift_id' => $this->shiftA->id,
            'role_id' => $supervisorRole->id,
        ]);
    }

    public function test_manual_delete_creates_coverage_shortage_for_removed_slot(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $supervisor = $this->supervisorWorker();
        $supervisorRole = Role::query()->where('code', 'supervisor')->firstOrFail();
        $shiftAId = $this->shiftA->id;

        $assignment = RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $supervisor->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-05',
            'source' => AssignmentSource::Auto,
        ]);

        $response = $this->deleteJson("/api/rosters/{$roster->id}/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $matchingShortage = collect($response->json('data.reports.coverage_shortages'))
            ->first(static fn (array $shortage): bool => $shortage['work_date'] === '2026-06-05'
                && (int) $shortage['shift_id'] === $shiftAId
                && (int) $shortage['role_id'] === $supervisorRole->id);

        self::assertNotNull($matchingShortage);
        self::assertSame('A', $matchingShortage['shift_code']);
        self::assertSame($supervisorRole->name, $matchingShortage['role_name']);
        self::assertSame(1, $matchingShortage['required']);
        self::assertSame(0, $matchingShortage['assigned']);
        self::assertSame(1, $matchingShortage['missing']);

        $this->assertDatabaseHas('coverage_shortages', [
            'roster_id' => $roster->id,
            'work_date' => '2026-06-05 00:00:00',
            'shift_id' => $this->shiftA->id,
            'role_id' => $supervisorRole->id,
            'required_count' => 1,
            'assigned_count' => 0,
        ]);
    }

    public function test_manual_create_refreshes_hours_shortfall_alert(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $worker = $this->assignableWorker();
        $worker->contract()->update(['min_monthly_hours' => 160]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 160,
            'scheduled_hours' => 120,
        ]);

        $response = $this->postJson("/api/rosters/{$roster->id}/assignments", [
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-01',
        ])
            ->assertCreated();

        $alert = collect($response->json('data.reports.hours_shortfalls'))
            ->firstWhere('worker_id', $worker->israeli_id);

        self::assertNotNull($alert);
        self::assertSame(8, $alert['scheduled_hours']);
        self::assertSame(160, $alert['min_hours']);
        self::assertSame(152, $alert['shortfall_hours']);

        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'type' => RosterAlertType::HoursShortfall->value,
            'scheduled_hours' => 8,
            'min_hours' => 160,
        ]);
    }

    public function test_manual_change_refreshes_hours_shortfall_alerts_for_both_workers(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $original = $this->assignableWorker();
        $original->contract()->update(['min_monthly_hours' => 160]);

        $replacement = Worker::query()
            ->active()
            ->whereHas('contract')
            ->whereKeyNot($original->israeli_id)
            ->where('role_id', $original->role_id)
            ->firstOrFail();
        $replacement->contract()->update(['min_monthly_hours' => 160, 'max_monthly_hours' => 240]);

        $assignment = RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $original->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-06',
            'source' => AssignmentSource::Auto,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $original->israeli_id,
            'min_hours' => 160,
            'scheduled_hours' => 0,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $replacement->israeli_id,
            'min_hours' => 160,
            'scheduled_hours' => 99,
        ]);

        $this->putJson("/api/rosters/{$roster->id}/assignments/{$assignment->id}", [
            'worker_id' => $replacement->israeli_id,
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $hoursShortfalls = collect($this->getJson("/api/rosters/{$roster->id}")
            ->assertOk()
            ->json('data.reports.hours_shortfalls'))
            ->keyBy('worker_id');

        self::assertSame(0, $hoursShortfalls[$original->israeli_id]['scheduled_hours']);
        self::assertSame(8, $hoursShortfalls[$replacement->israeli_id]['scheduled_hours']);

        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $original->israeli_id,
            'scheduled_hours' => 0,
        ]);
        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $replacement->israeli_id,
            'scheduled_hours' => 8,
        ]);
    }

    public function test_manual_delete_refreshes_hours_shortfall_alert(): void
    {
        $roster = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->user->id]);

        $worker = $this->assignableWorker();
        $worker->contract()->update(['min_monthly_hours' => 160]);

        $firstAssignment = RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->shiftA->id,
            'work_date' => '2026-06-07',
            'source' => AssignmentSource::Auto,
        ]);

        RosterAssignment::query()->create([
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'shift_id' => $this->shiftB->id,
            'work_date' => '2026-06-08',
            'source' => AssignmentSource::Auto,
        ]);

        RosterAlert::query()->create([
            'roster_id' => $roster->id,
            'type' => RosterAlertType::HoursShortfall,
            'worker_id' => $worker->israeli_id,
            'min_hours' => 160,
            'scheduled_hours' => 99,
        ]);

        $response = $this->deleteJson("/api/rosters/{$roster->id}/assignments/{$firstAssignment->id}")
            ->assertOk();

        $alert = collect($response->json('data.reports.hours_shortfalls'))
            ->firstWhere('worker_id', $worker->israeli_id);

        self::assertNotNull($alert);
        self::assertSame(8, $alert['scheduled_hours']);

        $this->assertDatabaseHas('roster_alerts', [
            'roster_id' => $roster->id,
            'worker_id' => $worker->israeli_id,
            'scheduled_hours' => 8,
        ]);
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

    private function supervisorWorker(): Worker
    {
        $supervisorRoleId = Role::query()->where('code', 'supervisor')->value('id');

        $worker = Worker::query()
            ->active()
            ->whereHas('contract')
            ->where('role_id', $supervisorRoleId)
            ->firstOrFail();

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
