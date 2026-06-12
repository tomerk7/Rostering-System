<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Workers;

use App\Enums\AssignmentSource;
use App\Exceptions\Workers\WorkerContractException;
use App\Models\Contract;
use App\Models\Roster;
use App\Models\RosterAssignment;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use App\Services\Workers\WorkerContractValidator;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class WorkerContractValidatorTest extends TestCase
{
    use RefreshDatabase;

    private WorkerContractValidator $validator;

    private Shift $morningShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceDataSeeder::class);

        $this->validator = $this->app->make(WorkerContractValidator::class);
        $this->morningShift = Shift::query()->where('code', 'A')->firstOrFail();
    }

    public function test_ignores_past_roster_assignments_when_checking_max_hours(): void
    {
        $user = User::factory()->create();
        $worker = Worker::factory()->create([
            'israeli_id' => $this->validIsraeliId(81111111),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create(['max_monthly_hours' => 240]);

        $past = Carbon::now()->startOfMonth()->subMonthsNoOverflow();
        $pastRoster = Roster::factory()
            ->forPeriod((int) $past->year, (int) $past->month)
            ->create(['created_by' => $user->id]);

        for ($day = 1; $day <= 22; $day++) {
            RosterAssignment::query()->create([
                'roster_id' => $pastRoster->id,
                'worker_id' => $worker->israeli_id,
                'shift_id' => $this->morningShift->id,
                'work_date' => $past->copy()->day($day)->toDateString(),
                'source' => AssignmentSource::Auto,
                'hourly_cost' => 50,
            ]);
        }

        $this->validator->assertMaxHoursAllowed($worker->israeli_id, 144);

        self::assertSame([], $this->validator->rosterHourConflicts($worker->israeli_id, 144));
    }

    public function test_blocks_when_upcoming_roster_assignments_exceed_proposed_max_hours(): void
    {
        $user = User::factory()->create();
        $worker = Worker::factory()->create([
            'israeli_id' => $this->validIsraeliId(81111112),
        ]);
        Contract::factory()
            ->for($worker)
            ->withAvailability([0, 1, 2, 3, 4, 5, 6], [$this->morningShift->id])
            ->create(['max_monthly_hours' => 240]);

        $roster = Roster::factory()
            ->forPeriod((int) now()->year, (int) now()->month)
            ->create(['created_by' => $user->id]);

        for ($day = 1; $day <= 20; $day++) {
            RosterAssignment::query()->create([
                'roster_id' => $roster->id,
                'worker_id' => $worker->israeli_id,
                'shift_id' => $this->morningShift->id,
                'work_date' => now()->startOfMonth()->addDays($day - 1)->toDateString(),
                'source' => AssignmentSource::Auto,
                'hourly_cost' => 50,
            ]);
        }

        $this->expectException(WorkerContractException::class);
        $this->expectExceptionMessage(
            Carbon::now()->startOfMonth()->format('F Y'),
        );

        $this->validator->assertMaxHoursAllowed($worker->israeli_id, 120);
    }

    private function validIsraeliId(int $base): string
    {
        return str_pad((string) ($base % 1_000_000_000), 9, '0', STR_PAD_LEFT);
    }
}
