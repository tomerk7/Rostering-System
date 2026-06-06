<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Rostering;

use App\Enums\AssignmentSource;
use App\Enums\RosterStatus;
use App\Exceptions\Rostering\RosterStatusException;
use App\Models\Role;
use App\Models\Roster;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use App\Services\Rostering\Data\GenerationResult;
use App\Services\Rostering\RosterPersister;
use Carbon\CarbonImmutable;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class RosterPersisterTest extends TestCase
{
    use RefreshDatabase;

    private const int YEAR = 2026;

    private const int MONTH = 2;

    private RosterPersister $persister;

    private User $admin;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->persister = new RosterPersister;
        $this->admin = User::factory()->create();
        $this->shift = Shift::query()->where('code', 'A')->firstOrFail();
    }

    public function test_save_persists_a_draft_roster_with_its_assignments(): void
    {
        $workers = $this->createGuards(2);
        $result = $this->generationResult([
            ['worker_id' => $workers[0]->id, 'shift_id' => $this->shift->id, 'work_date' => CarbonImmutable::create(self::YEAR, self::MONTH, 1)],
            ['worker_id' => $workers[1]->id, 'shift_id' => $this->shift->id, 'work_date' => CarbonImmutable::create(self::YEAR, self::MONTH, 1)],
        ]);

        $roster = $this->persister->save($result, $this->admin->id);

        self::assertSame(RosterStatus::Draft, $roster->status);
        self::assertNull($roster->published_at);
        self::assertNotNull($roster->generated_at);

        $this->assertDatabaseHas('rosters', [
            'id' => $roster->id,
            'year' => self::YEAR,
            'month' => self::MONTH,
            'status' => RosterStatus::Draft->value,
            'created_by' => $this->admin->id,
        ]);
        $this->assertDatabaseCount('roster_assignments', 2);
        $this->assertDatabaseHas('roster_assignments', [
            'roster_id' => $roster->id,
            'worker_id' => $workers[0]->id,
            'shift_id' => $this->shift->id,
            'work_date' => CarbonImmutable::create(self::YEAR, self::MONTH, 1)->toDateString(),
            'source' => AssignmentSource::Auto->value,
        ]);
    }

    public function test_save_with_no_assignments_still_creates_the_draft(): void
    {
        $roster = $this->persister->save($this->generationResult([]), $this->admin->id);

        $this->assertDatabaseHas('rosters', ['id' => $roster->id, 'status' => RosterStatus::Draft->value]);
        $this->assertDatabaseCount('roster_assignments', 0);
    }

    public function test_save_rolls_back_the_roster_when_assignment_insertion_fails(): void
    {
        $result = $this->generationResult([
            ['worker_id' => 999_999, 'shift_id' => $this->shift->id, 'work_date' => CarbonImmutable::create(self::YEAR, self::MONTH, 1)],
        ]);

        try {
            $this->persister->save($result, $this->admin->id);
            self::fail('Expected a foreign key violation to abort the save.');
        } catch (QueryException) {
            // Expected: the bad worker_id violates the FK.
        }

        $this->assertDatabaseCount('rosters', 0);
        $this->assertDatabaseCount('roster_assignments', 0);
    }

    public function test_publish_supersedes_any_previously_published_roster_for_the_month(): void
    {
        $previouslyPublished = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->published()
            ->create(['created_by' => $this->admin->id]);

        $draft = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->create(['created_by' => $this->admin->id]);

        $published = $this->persister->publish($draft);

        self::assertSame(RosterStatus::Published, $published->status);
        self::assertNotNull($published->published_at);

        $this->assertDatabaseHas('rosters', [
            'id' => $previouslyPublished->id,
            'status' => RosterStatus::Superseded->value,
        ]);
        $this->assertDatabaseHas('rosters', [
            'id' => $draft->id,
            'status' => RosterStatus::Published->value,
        ]);

        // The published-uniqueness rule still holds for the month.
        self::assertSame(
            1,
            Roster::query()->forPeriod(self::YEAR, self::MONTH)->published()->count(),
        );
    }

    public function test_publish_rejects_a_roster_that_is_not_a_draft(): void
    {
        $published = Roster::factory()
            ->forPeriod(self::YEAR, self::MONTH)
            ->published()
            ->create(['created_by' => $this->admin->id]);

        $this->expectException(RosterStatusException::class);

        $this->persister->publish($published);
    }

    /**
     * @return list<Worker>
     */
    private function createGuards(int $count): array
    {
        $roleId = (int) Role::query()->where('code', 'general_guard')->value('id');

        return Worker::factory()->count($count)->create(['role_id' => $roleId])->all();
    }

    /**
     * Build a GenerationResult with the given assignment tuples (source auto).
     *
     * @param  list<array{worker_id: int, shift_id: int, work_date: CarbonImmutable}>  $assignments
     */
    private function generationResult(array $assignments): GenerationResult
    {
        $rows = array_map(
            static fn (array $assignment): array => [
                'worker_id' => $assignment['worker_id'],
                'shift_id' => $assignment['shift_id'],
                'work_date' => $assignment['work_date'],
                'source' => AssignmentSource::Auto->value,
            ],
            $assignments,
        );

        return new GenerationResult(self::YEAR, self::MONTH, $rows, [], []);
    }
}
