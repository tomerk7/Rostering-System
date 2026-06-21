<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\Role;
use App\Data\Shift;
use App\Data\ShiftRoleRequirement;
use App\Data\Worker;
use App\Exceptions\WorkerContractException;
use App\Http\Request;
use App\Repositories\ContractRepository;
use App\Repositories\RoleRepository;
use App\Repositories\RosterAssignmentRepository;
use App\Repositories\ShiftRepository;
use App\Repositories\ShiftRoleRequirementRepository;
use App\Repositories\WorkerRepository;
use App\Support\DB;
use Throwable;

/**
 * Worker-domain business logic. Controllers stay thin and delegate here; this
 * layer composes repositories and shapes the data for the API.
 */
class WorkerService
{
    /**
     * Class constructor.
     *
     * @param RoleRepository $roles
     * @param ShiftRepository $shifts
     * @param ShiftRoleRequirementRepository $requirements
     */
    public function __construct(
        private RoleRepository $roles = new RoleRepository,
        private ShiftRepository $shifts = new ShiftRepository,
        private ShiftRoleRequirementRepository $requirements = new ShiftRoleRequirementRepository,
        private WorkerRepository $workers = new WorkerRepository,
        private ContractRepository $contracts = new ContractRepository,
        private RosterAssignmentRepository $assignments = new RosterAssignmentRepository,
        private RosterReportService $reportService = new RosterReportService,
    ) {}

    /** Pagination defaults, */
    private const int DEFAULT_PER_PAGE = 15;
    private const int MAX_PER_PAGE = 1000;

    /**
     * A filtered, paginated page of workers plus pagination meta.
     *
     * @param Request $request
     * @return array{data: list<array<string, mixed>>, meta: array<string, int|null>}
     */
    public function list(Request $request): array
    {
        $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
        $perPage = max(1, $perPage);
        $page = max(1, (int) $request->query('page', 1));

        $filters = $this->filters($request);

        $total = $this->workers->count($filters);
        $workers = $this->workers->page($filters, $perPage, ($page - 1) * $perPage);

        $count = count($workers);
        $from = $count > 0 ? ($page - 1) * $perPage + 1 : null;

        return [
            'data' => array_map(static fn (Worker $worker): array => $worker->toArray(), $workers),
            'meta' => [
                'current_page' => $page,
                'from' => $from,
                'last_page' => (int) max(1, ceil($total / $perPage)),
                'per_page' => $perPage,
                'to' => $from === null ? null : $from + $count - 1,
                'total' => $total,
            ],
        ];
    }

    /**
     * A single worker by israeli id, or null if not found (or trashed).
     *
     * @param string $israeliId
     * @return array<string, mixed>|null
     */
    public function find(string $israeliId): ?array
    {
        return $this->workers->find($israeliId)?->toArray();
    }

    /**
     * Whether a worker exists, including soft-deleted (for restore lookups).
     *
     * @param string $israeliId
     * @return bool
     */
    public function existsWithTrashed(string $israeliId): bool
    {
        return $this->workers->existsWithTrashed($israeliId);
    }

    /**
     * Whether any non-trashed workers exist (export guard).
     *
     * @return bool
     */
    public function hasWorkers(): bool
    {
        return $this->workers->count([]) > 0;
    }

    /**
     * Create a worker with its contract and availability.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     * @throws WorkerContractException
     */
    public function create(array $data): array
    {
        $israeliId = (string) $data['israeli_id'];
        $this->assertMaxHoursAllowed($israeliId, (int) $data['contract']['max_monthly_hours']);

        DB::transaction(function () use ($data, $israeliId): void {
            $this->workers->insert(
                $israeliId,
                (string) $data['full_name'],
                (int) $data['role_id'],
                filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN),
            );
            $this->writeContract($israeliId, $data);
            $this->reportService->refreshReportsForWorkers([$israeliId]);
        });

        /** @var array<string, mixed> $worker */
        $worker = $this->find($israeliId);

        return $worker;
    }

    /**
     * Update a worker with its contract and availability.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     * @throws WorkerContractException
     */
    public function update(string $israeliId, array $data): array
    {
        $this->assertMaxHoursAllowed($israeliId, (int) $data['contract']['max_monthly_hours']);

        DB::transaction(function () use ($israeliId, $data): void {
            $this->workers->updateFields(
                $israeliId,
                (string) $data['full_name'],
                (int) $data['role_id'],
                filter_var($data['is_active'], FILTER_VALIDATE_BOOLEAN),
            );
            $this->writeContract($israeliId, $data);
            $this->reportService->refreshReportsForWorkers([$israeliId]);
        });

        /** @var array<string, mixed> $worker */
        $worker = $this->find($israeliId);

        return $worker;
    }

    /**
     * Mark a worker inactive (no-op if already inactive).
     *
     * @param  array<string, mixed>  $worker  the already-loaded worker
     * @return void
     */
    public function deactivate(array $worker): void
    {
        if ($worker['is_active'] !== true) {
            return;
        }

        $israeliId = (string) $worker['israeli_id'];

        DB::transaction(function () use ($israeliId): void {
            $this->workers->deactivate($israeliId);
            $this->reportService->refreshReportsForWorkers([$israeliId]);
        });
    }

    /**
     * Soft-delete a worker.
     *
     * @param string $israeliId
     * @return void
     */
    public function softDelete(string $israeliId): void
    {
        DB::transaction(function () use ($israeliId): void {
            $this->workers->softDelete($israeliId);
            $this->reportService->refreshReportsForWorkers([$israeliId]);
        });
    }

    /**
     * Restore a soft-deleted worker as active.
     *
     * @param string $israeliId
     * @return array<string, mixed>
     */
    public function restore(string $israeliId): array
    {
        DB::transaction(function () use ($israeliId): void {
            $this->workers->restore($israeliId);
            $this->reportService->refreshReportsForWorkers([$israeliId]);
        });

        /** @var array<string, mixed> $worker */
        $worker = $this->find($israeliId);

        return $worker;
    }

    /**
     * Soft-delete every non-archived worker, clear their upcoming-roster
     * assignments + alerts, and recompute upcoming coverage. Returns the number
     * of workers deleted.
     *
     * @return int
     * @throws Throwable
     */
    public function deleteAll(): int
    {
        $deleted = DB::transaction(function (): int {
            $deleted = $this->workers->count([]);

            if ($deleted === 0) {
                return 0;
            }

            $this->workers->softDeleteAll();
            $this->assignments->deleteForUpcomingRosters();
            $this->reportService->removeUpcomingAlerts();

            return $deleted;
        });

        if ($deleted > 0) {
            $this->reportService->refreshCoverageForUpcomingRosters();
        }

        return $deleted;
    }

    /**
     * Restore every archived worker as active and refresh upcoming roster
     * reports. Returns the number restored.
     *
     * @return int
     * @throws Throwable
     */
    public function restoreAll(): int
    {
        $workerIds = $this->workers->trashedIds();

        if ($workerIds === []) {
            return 0;
        }

        DB::transaction(function (): void {
            $this->workers->restoreAllTrashed();
        });

        $this->reportService->refreshReportsForWorkers($workerIds);

        return count($workerIds);
    }

    /**
     * Persist the contract + availability for a worker.
     *
     * @param string $israeliId
     * @param  array<string, mixed>  $data
     * @return void
     */
    private function writeContract(string $israeliId, array $data): void
    {
        $contractId = $this->contracts->updateOrCreateForWorker(
            $israeliId,
            (string) $data['contract']['hourly_cost'],
            (int) $data['contract']['min_monthly_hours'],
            (int) $data['contract']['max_monthly_hours'],
        );
        $this->contracts->replaceAvailability($contractId, $data['availability']);
    }

    /**
     * Guard against lowering max monthly hours below already-assigned roster hours.
     *
     * @param string $israeliId
     * @param int $maxMonthlyHours
     * @return void
     * @throws WorkerContractException
     */
    private function assertMaxHoursAllowed(string $israeliId, int $maxMonthlyHours): void
    {
        $conflicts = $this->assignments->hourConflicts($israeliId, $maxMonthlyHours);

        if ($conflicts !== []) {
            throw WorkerContractException::maxHoursBelowAssignedHours($maxMonthlyHours, $conflicts);
        }
    }

    /**
     * Pull the recognized list filters off the request.
     *
     * @param Request $request
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        $filters = ['trashed' => (string) $request->query('trashed', '')];

        if ($request->query('search') !== null) {
            $filters['search'] = (string) $request->query('search');
        }
        if ($request->query('role_id') !== null) {
            $filters['role_id'] = (int) $request->query('role_id');
        }
        if ($request->query('role_code') !== null) {
            $filters['role_code'] = (string) $request->query('role_code');
        }
        if ($request->hasQuery('is_active')) {
            $filters['is_active'] = filter_var($request->query('is_active'), FILTER_VALIDATE_BOOL);
        }

        return $filters;
    }

    /**
     * Roles, shifts, and per-shift staffing demand for worker forms.
     *
     * @return array
     */
    public function referenceData(): array
    {
        return [
            'roles' => array_map(
                static fn (Role $role): array => $role->toArray(),
                $this->roles->all(),
            ),
            'shifts' => array_map(
                static fn (Shift $shift): array => $shift->toArray(),
                $this->shifts->all(),
            ),
            'shift_role_requirements' => array_map(
                static fn (ShiftRoleRequirement $requirement): array => $requirement->toArray(),
                $this->requirements->all(withRole: true),
            ),
        ];
    }
}
