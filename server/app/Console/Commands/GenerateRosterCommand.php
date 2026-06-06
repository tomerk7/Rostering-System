<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\Worker;
use App\Services\Rostering\RosterGenerator;
use App\Services\Rostering\RosterPersister;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

final class GenerateRosterCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'roster:generate
                            {year : Target year, e.g. 2026}
                            {month : Target month (1-12)}
                            {--save : Persist the preview as a draft roster}
                            {--publish : Publish the draft after saving (implies --save)}
                            {--user= : User id for created_by when saving (defaults to first user)}';

    /**
     * @var string
     */
    protected $description = 'Generate a monthly roster preview and optionally save it';

    public function handle(RosterGenerator $generator, RosterPersister $persister): int
    {
        $year = (int) $this->argument('year');
        $month = (int) $this->argument('month');

        if ($month < 1 || $month > 12) {
            $this->error('Month must be between 1 and 12.');

            return self::FAILURE;
        }

        $eligibleWorkers = Worker::query()->active()->whereHas('contract')->count();

        if ($eligibleWorkers === 0) {
            $this->warn('No active workers with contracts found. Import or create workers first.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Generating roster for %d-%02d using %d eligible worker(s)...',
            $year,
            $month,
            $eligibleWorkers,
        ));

        $result = $generator->generate($year, $month);

        $this->newLine();
        $this->line(sprintf('Assignments: %d', count($result->assignments)));
        $this->line(sprintf('Coverage shortages: %d', count($result->coverageShortages)));
        $this->line(sprintf('Hours shortfalls: %d', count($result->hoursShortfalls)));

        if ($result->hasCoverageShortages()) {
            $this->newLine();
            $this->warn('Coverage shortages (first 10):');
            $this->table(
                ['Date', 'Shift', 'Role', 'Required', 'Assigned'],
                $this->formatCoverageShortages(array_slice($result->coverageShortages, 0, 10)),
            );
        }

        if ($result->hasHoursShortfalls()) {
            $this->newLine();
            $this->warn('Hours shortfalls (first 10):');
            $this->table(
                ['Worker', 'Min hours', 'Scheduled'],
                $this->formatHoursShortfalls(array_slice($result->hoursShortfalls, 0, 10)),
            );
        }

        if (! $this->option('save') && ! $this->option('publish')) {
            $this->newLine();
            $this->comment('Preview only — pass --save to persist as a draft, or --publish to save and publish.');

            return self::SUCCESS;
        }

        $user = $this->resolveUser();

        if ($user === null) {
            return self::FAILURE;
        }

        $roster = $persister->save($result, (int) $user->id);

        $this->newLine();
        $this->info(sprintf(
            'Saved draft roster #%d (%d assignments) as user #%d (%s).',
            $roster->id,
            count($result->assignments),
            $user->id,
            $user->email,
        ));

        if ($this->option('publish')) {
            $roster = $persister->publish($roster);
            $this->info(sprintf('Published roster #%d.', $roster->id));
        }

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $userOption = $this->option('user');

        if ($userOption !== null) {
            $user = User::query()->whereKey((int) $userOption)->first();

            if ($user === null) {
                $this->error(sprintf('User #%d does not exist.', (int) $userOption));

                return null;
            }

            return $user;
        }

        $user = User::query()->orderBy('id')->first();

        if ($user === null) {
            $this->error('No users found. Create a user or pass --user=<id>.');

            return null;
        }

        return $user;
    }

    /**
     * @param  list<array{work_date: CarbonImmutable, shift_id: int, role_id: int, required: int, assigned: int}>  $shortages
     * @return list<array{string, string, string, int, int}>
     */
    private function formatCoverageShortages(array $shortages): array
    {
        $shiftNames = Shift::query()->pluck('code', 'id');
        $roleNames = Role::query()->pluck('code', 'id');

        return array_map(
            static fn (array $shortage): array => [
                $shortage['work_date']->toDateString(),
                (string) ($shiftNames[$shortage['shift_id']] ?? $shortage['shift_id']),
                (string) ($roleNames[$shortage['role_id']] ?? $shortage['role_id']),
                $shortage['required'],
                $shortage['assigned'],
            ],
            $shortages,
        );
    }

    /**
     * @param  list<array{worker_id: int, min_hours: int, scheduled_hours: int}>  $shortfalls
     * @return list<array{string, int, int}>
     */
    private function formatHoursShortfalls(array $shortfalls): array
    {
        $workerNames = Worker::query()
            ->whereIn('id', array_column($shortfalls, 'worker_id'))
            ->pluck('full_name', 'id');

        return array_map(
            static fn (array $shortfall): array => [
                (string) ($workerNames[$shortfall['worker_id']] ?? '#'.$shortfall['worker_id']),
                $shortfall['min_hours'],
                $shortfall['scheduled_hours'],
            ],
            $shortfalls,
        );
    }
}
