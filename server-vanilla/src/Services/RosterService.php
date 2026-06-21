<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\Roster;
use App\Enums\RosterStatus;
use App\Repositories\ContractRepository;
use App\Repositories\CoverageShortageRepository;
use App\Repositories\RosterAlertRepository;
use App\Repositories\RosterAssignmentRepository;
use App\Repositories\RosterGenerationJobRepository;
use App\Repositories\RosterRepository;
use App\Repositories\UserRepository;
use App\Services\Rostering\Data\DistributionPreference;
use App\Services\Rostering\OptimizerPenaltyAdvisor;
use App\Services\Rostering\RosterGenerator;
use App\Support\DB;
use Exception;
use Random\RandomException;
use Throwable;

/**
 * Roster-domain business logic. Controllers stay thin and delegate here.
 */
class RosterService
{
    /**
     * Class constructor.
     *
     * @param RosterRepository $rosters
     * @param RosterAssignmentRepository $assignments
     * @param UserRepository $users
     * @param RosterReportService $reportService
     * @param RosterGenerator $generator
     * @param RosterGenerationJobRepository $jobs
     * @param ContractRepository $contracts
     * @param CoverageShortageRepository $coverageShortages
     * @param RosterAlertRepository $alerts
     * @param OptimizerPenaltyAdvisor $penaltyAdvisor
     */
    public function __construct(
        private RosterRepository $rosters = new RosterRepository(),
        private RosterAssignmentRepository $assignments = new RosterAssignmentRepository(),
        private UserRepository $users = new UserRepository(),
        private RosterReportService $reportService = new RosterReportService(),
        private RosterGenerator $generator = new RosterGenerator(),
        private RosterGenerationJobRepository $jobs = new RosterGenerationJobRepository(),
        private ContractRepository $contracts = new ContractRepository(),
        private CoverageShortageRepository $coverageShortages = new CoverageShortageRepository(),
        private RosterAlertRepository $alerts = new RosterAlertRepository(),
        private OptimizerPenaltyAdvisor $penaltyAdvisor = new OptimizerPenaltyAdvisor(),
    ) {}

    /**
     * All rosters as API arrays (newest first), with assignment counts.
     *
     * @return list<array<string, mixed>>
     */
    public function list(): array
    {
        return array_map(
            static fn (Roster $roster): array => $roster->toArray(),
            $this->rosters->all(),
        );
    }

    /**
     * A single roster by id, or null when it does not exist.
     *
     * @param int $id
     * @return Roster|null
     */
    public function find(int $id): ?Roster
    {
        return $this->rosters->find($id);
    }

    /**
     * One roster as the detailed API shape (mirrors RosterResource for `show`):
     * creator, full assignment count, persisted reports + summary, and — unless
     * omitted — the enriched assignments (optionally filtered to one date/shift).
     *
     * @param Roster $roster
     * @param string|null $date
     * @param int|null $shiftId
     * @param bool $includeAssignments
     * @return array<string, mixed>
     */
    public function loadDetails(
        Roster $roster,
        ?string $date = null,
        ?int $shiftId = null,
        bool $includeAssignments = true,
    ): array {
        $creator = $roster->createdBy === null ? null : $this->users->findById($roster->createdBy);

        $data = [
            'id' => $roster->id,
            'year' => (int) substr($roster->periodStart, 0, 4),
            'month' => (int) substr($roster->periodStart, 5, 2),
            'status' => $roster->status,
            'generated_at' => Roster::iso($roster->generatedAt),
            'created_by' => $roster->createdBy,
            'creator' => $creator === null ? null : ['id' => $creator->id, 'email' => $creator->email],
            'assignments_count' => $roster->assignmentsCount,
        ];

        if ($includeAssignments) {
            $data['assignments'] = array_map(
                static fn ($assignment): array => $assignment->toArray(),
                $this->assignments->listForDetails($roster->id, $date, $shiftId),
            );
        }

        $report = $this->reportService->loadReport($roster->id, (int) $roster->assignmentsCount);
        $data['reports'] = $report['reports'];
        $data['summary'] = $report['summary'];
        $data['created_at'] = Roster::iso($roster->createdAt);
        $data['updated_at'] = Roster::iso($roster->updatedAt);

        return $data;
    }

    /**
     * Queue creation of a roster for a month: delete any existing roster for the
     * period, insert a processing stub, and enqueue the generation job. Returns
     * the stub (status processing).
     *
     * @param int $year
     * @param int $month
     * @param int $userId
     * @param bool $optimizeCost
     * @param DistributionPreference|null $preference
     * @return Roster
     * @throws RandomException|Throwable
     */
    public function queueStore(int $year, int $month, int $userId, bool $optimizeCost = false, ?DistributionPreference $preference = null): Roster
    {
        return DB::transaction(function () use ($year, $month, $userId, $optimizeCost, $preference): Roster {
            $this->rosters->deleteForPeriod($year, $month);

            $roster = $this->rosters->createStub(
                sprintf('%04d-%02d-01', $year, $month),
                $userId,
                RosterStatus::Processing->value,
            );

            $this->jobs->enqueue($roster->id, $optimizeCost, $preference);

            return $roster;
        });
    }

    /**
     * Queue regeneration of an existing roster: mark it processing and enqueue the
     * generation job. Returns the fresh roster (status processing).
     *
     * @param Roster $roster
     * @param bool $optimizeCost
     * @param DistributionPreference|null $preference
     * @return Roster
     * @throws RandomException
     */
    public function queueRegeneration(Roster $roster, bool $optimizeCost = false, ?DistributionPreference $preference = null): Roster
    {
        $this->rosters->updateStatus($roster->id, RosterStatus::Processing->value);

        $this->jobs->enqueue($roster->id, $optimizeCost, $preference);

        return $this->rosters->find($roster->id) ?? $roster;
    }

    /**
     * Process a queued roster generation (worker daemon): regenerate if the roster
     * already has assignments, otherwise fill the new stub; then mark it ready.
     *
     * @param int $rosterId
     * @param bool $optimizeCost
     * @param DistributionPreference|null $preference
     * @return void
     * @throws Exception
     */
    public function processGeneration(int $rosterId, bool $optimizeCost = false, ?DistributionPreference $preference = null): void
    {
        $roster = $this->rosters->find($rosterId);

        if ($roster === null) {
            throw new Exception("Roster {$rosterId} not found.");
        }

        if ($this->rosters->hasAssignments($rosterId)) {
            $this->regenerate($roster, $optimizeCost, $preference);
        } else {
            $this->fillNewRoster($roster, $optimizeCost, $preference);
        }

        $this->rosters->updateStatus($rosterId, RosterStatus::Ready->value);
    }

    /**
     * Delete a roster.
     *
     * @param int $rosterId
     * @return void
     */
    public function delete(int $rosterId): void
    {
        $this->rosters->deleteById($rosterId);
    }

    /**
     * Record a failed queued roster generation.
     *
     * @param int $rosterId
     * @return void
     */
    public function markGenerationFailed(int $rosterId): void
    {
        $this->rosters->updateStatus($rosterId, RosterStatus::Failed->value);
    }

    /**
     * Regenerate assignments for an existing roster, keeping the same roster id.
     *
     * @param Roster $roster
     * @param bool $optimizeCost
     * @param DistributionPreference|null $preference
     * @return void
     * @throws Exception
     */
    private function regenerate(Roster $roster, bool $optimizeCost, ?DistributionPreference $preference): void
    {
        $year = (int) substr($roster->periodStart, 0, 4);
        $month = (int) substr($roster->periodStart, 5, 2);

        [$balanceWeight, $shortfallPenaltyPerHour] = $this->resolveOptimizerPenalties($preference);

        $result = $this->generator->generate($year, $month, $optimizeCost, $balanceWeight, $shortfallPenaltyPerHour);

        DB::transaction(function () use ($roster, $result): void {
            $this->assignments->deleteAllForRoster($roster->id);
            $this->alerts->deleteAllForRosters([$roster->id]);
            $this->coverageShortages->deleteForRoster($roster->id);

            $this->rosters->markGenerated($roster->id);

            $this->insertAssignments($roster->id, $result->assignments);
            $this->reportService->insertAlerts($roster->id, $result);
        });
    }

    /**
     * Generate and persist assignments into a queued roster stub.
     *
     * @param Roster $roster
     * @param bool $optimizeCost
     * @param DistributionPreference|null $preference
     * @return void
     * @throws Exception
     */
    private function fillNewRoster(Roster $roster, bool $optimizeCost, ?DistributionPreference $preference): void
    {
        $year = (int) substr($roster->periodStart, 0, 4);
        $month = (int) substr($roster->periodStart, 5, 2);

        [$balanceWeight, $shortfallPenaltyPerHour] = $this->resolveOptimizerPenalties($preference);

        $result = $this->generator->generate($year, $month, $optimizeCost, $balanceWeight, $shortfallPenaltyPerHour);

        $this->rosters->markGenerated($roster->id);

        $this->insertAssignments($roster->id, $result->assignments);
        $this->reportService->insertAlerts($roster->id, $result);
    }

    /**
     * Resolve the optimizer penalties for a generation run from the chosen
     * distribution preference: the preset's balance weight plus a shortfall
     * penalty scaled to the priciest active worker. Returns [null, null] when no
     * preference was chosen (the optimizer then falls back to its own defaults).
     *
     * @param DistributionPreference|null $preference
     * @return array{0: float|null, 1: float|null}
     */
    private function resolveOptimizerPenalties(?DistributionPreference $preference): array
    {
        if ($preference === null) {
            return [null, null];
        }

        $penalties = $this->penaltyAdvisor->penaltiesFor($preference);

        return [$penalties['balanceWeight'], $penalties['shortfallPenaltyPerHour']];
    }

    /**
     * Bulk-insert the generation assignments for a roster, snapshotting each
     * worker's hourly cost from their contract.
     *
     * @param int $rosterId
     * @param  list<array{worker_id: string, shift_id: int, work_date: \Carbon\CarbonImmutable, source: string}>  $assignments
     * @return void
     * @throws Exception
     */
    private function insertAssignments(int $rosterId, array $assignments): void
    {
        if ($assignments === []) {
            return;
        }

        $costs = $this->contracts->hourlyCostByWorker();

        $rows = array_map(
            static fn (array $assignment): array => [
                'worker_id' => $assignment['worker_id'],
                'shift_id' => $assignment['shift_id'],
                'work_date' => $assignment['work_date']->toDateString(),
                'source' => $assignment['source'],
                'hourly_cost' => $costs[$assignment['worker_id']]
                    ?? throw new Exception("Missing contract rate for worker {$assignment['worker_id']}."),
            ],
            $assignments,
        );

        $this->assignments->insertGenerated($rosterId, $rows);
    }
}
