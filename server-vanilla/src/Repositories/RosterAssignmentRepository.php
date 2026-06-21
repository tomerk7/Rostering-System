<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Role;
use App\Data\RosterAssignment;
use App\Data\Shift;
use App\Data\Worker;
use App\Support\DB;
use DateMalformedStringException;
use PDO;

/**
 * Reads and writes the `roster_assignments` table.
 */
final class RosterAssignmentRepository
{
    /** Enriched assignment select shared by the range and details reads. */
    private const string SELECT = 'SELECT ra.id, ra.roster_id, ra.worker_id, ra.shift_id, ra.work_date,
                    ra.source, ra.hourly_cost,
                    w.full_name AS worker__full_name, w.is_active::int AS worker__is_active,
                    w.deleted_at AS worker__deleted_at,
                    r.id AS role__id, r.code AS role__code, r.name AS role__name,
                    s.code AS shift__code, s.start_time AS shift__start_time,
                    s.end_time AS shift__end_time, s.duration_hours AS shift__duration_hours
             FROM roster_assignments ra
             JOIN workers w ON w.israeli_id = ra.worker_id
             LEFT JOIN roles r ON r.id = w.role_id
             JOIN shifts s ON s.id = ra.shift_id';

    private const string ORDER = 'ORDER BY ra.work_date, ra.shift_id, ra.worker_id';

    private PDO $pdo;

    /**
     * Class constructor.
     *
     * @param PDO|null $pdo
     */
    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? DB::connect();
    }

    /**
     * Bare assignment rows (id, worker_id, shift_id, work_date) for a roster,
     * for report recomputation and validity pruning.
     *
     * @param int $rosterId
     * @return list<array{id: int, worker_id: string, shift_id: int, work_date: string}>
     */
    public function rawForRoster(int $rosterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, worker_id, shift_id, work_date
             FROM roster_assignments
             WHERE roster_id = ?
             ORDER BY work_date, shift_id, worker_id',
        );
        $stmt->execute([$rosterId]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'worker_id' => $row['worker_id'],
            'shift_id' => (int) $row['shift_id'],
            'work_date' => substr((string) $row['work_date'], 0, 10),
        ], $stmt->fetchAll());
    }

    /**
     * Bare assignment rows for specific workers in a roster (validity pruning of
     * changed workers' assignments).
     *
     * @param int $rosterId
     * @param  list<string>  $workerIds
     * @return list<array{id: int, worker_id: string, shift_id: int, work_date: string}>
     */
    public function rawForRosterWorkers(int $rosterId, array $workerIds): array
    {
        if ($workerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT id, worker_id, shift_id, work_date
             FROM roster_assignments
             WHERE roster_id = ? AND worker_id IN ($placeholders)
             ORDER BY work_date, shift_id, worker_id",
        );
        $stmt->execute([$rosterId, ...$workerIds]);

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'worker_id' => $row['worker_id'],
            'shift_id' => (int) $row['shift_id'],
            'work_date' => substr((string) $row['work_date'], 0, 10),
        ], $stmt->fetchAll());
    }

    /**
     * Scheduled hours (sum of shift durations) per worker for the given workers
     * in a roster, keyed by worker id. Drives hours-shortfall alert rebuilds.
     *
     * @param int $rosterId
     * @param  list<string>  $workerIds
     * @return array<string, int>
     */
    public function scheduledHoursForWorkers(int $rosterId, array $workerIds): array
    {
        if ($workerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT ra.worker_id, SUM(s.duration_hours) AS scheduled_hours
             FROM roster_assignments ra
             JOIN shifts s ON s.id = ra.shift_id
             WHERE ra.roster_id = ? AND ra.worker_id IN ($placeholders)
             GROUP BY ra.worker_id",
        );
        $stmt->execute([$rosterId, ...$workerIds]);

        $hours = [];
        foreach ($stmt->fetchAll() as $row) {
            $hours[$row['worker_id']] = (int) $row['scheduled_hours'];
        }

        return $hours;
    }

    /**
     * Delete every assignment in current/future rosters (used by delete-all,
     * which clears all workers). Past rosters are preserved as history.
     *
     * @return void
     */
    public function deleteForUpcomingRosters(): void
    {
        $this->pdo->prepare(
            'DELETE FROM roster_assignments
             WHERE roster_id IN (SELECT id FROM rosters WHERE period_start >= ?)',
        )->execute([gmdate('Y-m-01')]);
    }

    /**
     * A single assignment by id (without worker/shift relations loaded), or null.
     *
     * @param int $id
     * @return RosterAssignment|null
     */
    public function find(int $id): ?RosterAssignment
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, roster_id, worker_id, shift_id, work_date, source, hourly_cost
             FROM roster_assignments WHERE id = ?',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : new RosterAssignment(
            (int) $row['id'],
            (int) $row['roster_id'],
            $row['worker_id'],
            (int) $row['shift_id'],
            substr((string) $row['work_date'], 0, 10),
            $row['source'],
            $row['hourly_cost'],
        );
    }

    /**
     * Number of workers of a given role already assigned to a (shift, date) in a
     * roster — the role's current coverage for assertRoleCapacity.
     *
     * @param int $rosterId
     * @param int $shiftId
     * @param string $workDate
     * @param int $roleId
     * @return int
     */
    public function countRoleAssigned(int $rosterId, int $shiftId, string $workDate, int $roleId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*)
             FROM roster_assignments ra
             JOIN workers w ON w.israeli_id = ra.worker_id
             WHERE ra.roster_id = ? AND ra.shift_id = ? AND ra.work_date = ? AND w.role_id = ?',
        );
        $stmt->execute([$rosterId, $shiftId, $workDate, $roleId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Whether a worker already holds the exact (roster, shift, date) slot.
     *
     * @param int $rosterId
     * @param string $workerId
     * @param int $shiftId
     * @param string $workDate
     * @return bool
     */
    public function slotExists(int $rosterId, string $workerId, int $shiftId, string $workDate): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM roster_assignments
             WHERE roster_id = ? AND worker_id = ? AND shift_id = ? AND work_date = ?
             LIMIT 1',
        );
        $stmt->execute([$rosterId, $workerId, $shiftId, $workDate]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * How many shifts a worker already holds on a given date in a roster.
     *
     * @param int $rosterId
     * @param string $workerId
     * @param string $workDate
     * @return int
     */
    public function countForWorkerOnDate(int $rosterId, string $workerId, string $workDate): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM roster_assignments
             WHERE roster_id = ? AND worker_id = ? AND work_date = ?',
        );
        $stmt->execute([$rosterId, $workerId, $workDate]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Total scheduled hours a worker already has in a roster (sum of shift
     * durations).
     *
     * @param int $rosterId
     * @param string $workerId
     * @return int
     */
    public function sumDurationForWorker(int $rosterId, string $workerId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(s.duration_hours), 0)
             FROM roster_assignments ra
             JOIN shifts s ON s.id = ra.shift_id
             WHERE ra.roster_id = ? AND ra.worker_id = ?',
        );
        $stmt->execute([$rosterId, $workerId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Insert one assignment; returns its new id.
     *
     * @param int $rosterId
     * @param string $workerId
     * @param int $shiftId
     * @param string $workDate
     * @param string $source
     * @param string $hourlyCost
     * @return int
     */
    public function insert(int $rosterId, string $workerId, int $shiftId, string $workDate, string $source, string $hourlyCost): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO roster_assignments
                (roster_id, worker_id, shift_id, work_date, source, hourly_cost, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, now(), now())
             RETURNING id',
        );
        $stmt->execute([$rosterId, $workerId, $shiftId, $workDate, $source, $hourlyCost]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Bulk-insert generated assignments for a roster. Each row carries the
     * snapshotted `hourly_cost` (a raw numeric string).
     * single-statement insert in RosterService::insertAssignments.
     *
     * @param int $rosterId
     * @param  list<array{worker_id: string, shift_id: int, work_date: string, source: string, hourly_cost: string}>  $rows
     * @return void
     */
    public function insertGenerated(int $rosterId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $values = [];
        $params = [];

        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?, ?, ?, now(), now())';
            $params[] = $rosterId;
            $params[] = $row['worker_id'];
            $params[] = $row['shift_id'];
            $params[] = $row['work_date'];
            $params[] = $row['source'];
            $params[] = $row['hourly_cost'];
        }

        $sql = 'INSERT INTO roster_assignments
                    (roster_id, worker_id, shift_id, work_date, source, hourly_cost, created_at, updated_at)
                VALUES ' . implode(', ', $values);

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Delete every assignment for a roster.
     * `$roster->assignments()->delete()` used by regenerate.
     *
     * @param int $rosterId
     * @return void
     */
    public function deleteAllForRoster(int $rosterId): void
    {
        $this->pdo->prepare('DELETE FROM roster_assignments WHERE roster_id = ?')->execute([$rosterId]);
    }

    /**
     * Delete one assignment by id.
     *
     * @param int $id
     * @return void
     */
    public function deleteById(int $id): void
    {
        $this->pdo->prepare('DELETE FROM roster_assignments WHERE id = ?')->execute([$id]);
    }

    /**
     * Delete assignments by id.
     *
     * @param  list<int>  $ids
     * @return void
     */
    public function deleteByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $this->pdo->prepare("DELETE FROM roster_assignments WHERE id IN ($placeholders)")->execute($ids);
    }

    /**
     * Current/future rosters where the worker's already-assigned hours exceed the
     * proposed max monthly hours.
     *
     * @param string $workerId
     * @param int $maxMonthlyHours
     * @return list<array{period_label: string, assigned_hours: int}>
     * @throws DateMalformedStringException
     */
    public function hourConflicts(string $workerId, int $maxMonthlyHours): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.period_start, SUM(s.duration_hours) AS assigned_hours
             FROM roster_assignments ra
             JOIN rosters r ON r.id = ra.roster_id
             JOIN shifts s ON s.id = ra.shift_id
             WHERE ra.worker_id = ?
               AND r.period_start >= ?
             GROUP BY r.id, r.period_start
             HAVING SUM(s.duration_hours) > ?
             ORDER BY r.period_start',
        );
        $stmt->execute([$workerId, gmdate('Y-m-01'), $maxMonthlyHours]);

        return array_map(static fn (array $row): array => [
            'period_label' => (new \DateTimeImmutable($row['period_start']))->format('F Y'),
            'assigned_hours' => (int) $row['assigned_hours'],
        ], $stmt->fetchAll());
    }

    /**
     * Assignments for a roster within an inclusive date range, ordered by
     * work_date, shift_id, worker_id — each with its worker (and role) and shift
     * loaded. The worker
     * join is unscoped (Uses withTrashed), so soft-deleted workers stay.
     *
     * @param int $rosterId
     * @param string $fromDate
     * @param string $toDate
     * @return list<RosterAssignment>
     */
    public function listForRange(int $rosterId, string $fromDate, string $toDate): array
    {
        $stmt = $this->pdo->prepare(
            self::SELECT . ' WHERE ra.roster_id = ? AND ra.work_date BETWEEN ? AND ? ' . self::ORDER,
        );
        $stmt->execute([$rosterId, $fromDate, $toDate]);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * All assignments for a roster, ordered by work_date, shift_id, worker_id,
     * optionally filtered to one work_date and/or one shift — each with its worker
     * (and role) and shift loaded.
     *
     * @param int $rosterId
     * @param string|null $date
     * @param int|null $shiftId
     * @return list<RosterAssignment>
     */
    public function listForDetails(int $rosterId, ?string $date = null, ?int $shiftId = null): array
    {
        $where = 'WHERE ra.roster_id = ?';
        $params = [$rosterId];

        if ($date !== null && $date !== '') {
            $where .= ' AND ra.work_date = ?';
            $params[] = $date;
        }

        if ($shiftId) {
            $where .= ' AND ra.shift_id = ?';
            $params[] = $shiftId;
        }

        $stmt = $this->pdo->prepare(self::SELECT . ' ' . $where . ' ' . self::ORDER);
        $stmt->execute($params);

        return array_map(self::hydrate(...), $stmt->fetchAll());
    }

    /**
     * Total assigned hours per worker across the whole roster (not date-filtered),
     * keyed by worker id. Mirrors the `assigned_hours_by_worker` meta.
     *
     * @param int $rosterId
     * @return array<string, int>
     */
    public function assignedHoursByWorker(int $rosterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ra.worker_id, SUM(s.duration_hours) AS assigned_hours
             FROM roster_assignments ra
             JOIN shifts s ON s.id = ra.shift_id
             WHERE ra.roster_id = ?
             GROUP BY ra.worker_id
             ORDER BY ra.worker_id',
        );
        $stmt->execute([$rosterId]);

        $hours = [];
        foreach ($stmt->fetchAll() as $row) {
            $hours[$row['worker_id']] = (int) $row['assigned_hours'];
        }

        return $hours;
    }

    /**
     * Aggregated per-worker hours and snapshot cost for a roster, ordered by
     * israeli_id. Cost uses each assignment's snapshotted hourly_cost. Joins are
     * unscoped query-builder joins (soft-deleted workers included), and the
     * contract is optional (null hours fall back to 0).
     *
     * @param int $rosterId
     * @return list<array{
     *     israeli_id: string,
     *     full_name: string,
     *     role_name: string,
     *     min_monthly_hours: int|null,
     *     max_monthly_hours: int|null,
     *     actual_hours: int,
     *     total_cost: float
     * }>
     */
    public function statsRows(int $rosterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT workers.israeli_id,
                    workers.full_name,
                    roles.name AS role_name,
                    contracts.min_monthly_hours,
                    contracts.max_monthly_hours,
                    SUM(shifts.duration_hours) AS actual_hours,
                    SUM(shifts.duration_hours * roster_assignments.hourly_cost) AS total_cost
             FROM roster_assignments
             JOIN shifts ON shifts.id = roster_assignments.shift_id
             JOIN workers ON workers.israeli_id = roster_assignments.worker_id
             JOIN roles ON roles.id = workers.role_id
             LEFT JOIN contracts ON contracts.worker_id = workers.israeli_id
             WHERE roster_assignments.roster_id = ?
             GROUP BY workers.israeli_id, workers.full_name, roles.name,
                      contracts.min_monthly_hours, contracts.max_monthly_hours
             ORDER BY workers.israeli_id',
        );
        $stmt->execute([$rosterId]);

        return array_map(static fn (array $row): array => [
            'israeli_id' => $row['israeli_id'],
            'full_name' => $row['full_name'],
            'role_name' => $row['role_name'],
            'min_monthly_hours' => $row['min_monthly_hours'] === null ? null : (int) $row['min_monthly_hours'],
            'max_monthly_hours' => $row['max_monthly_hours'] === null ? null : (int) $row['max_monthly_hours'],
            'actual_hours' => (int) $row['actual_hours'],
            'total_cost' => (float) $row['total_cost'],
        ], $stmt->fetchAll());
    }

    /**
     * Hydrate an enriched assignment row (from self::SELECT) into a DTO.
     *
     * @param  array<string, mixed>  $row
     * @return RosterAssignment
     */
    private static function hydrate(array $row): RosterAssignment
    {
        return new RosterAssignment(
            (int) $row['id'],
            (int) $row['roster_id'],
            $row['worker_id'],
            (int) $row['shift_id'],
            substr((string) $row['work_date'], 0, 10),
            $row['source'],
            $row['hourly_cost'],
            new Worker(
                israeliId: $row['worker_id'],
                fullName: $row['worker__full_name'],
                isActive: (bool) $row['worker__is_active'],
                deletedAt: $row['worker__deleted_at'],
                role: $row['role__id'] === null ? null : new Role(
                    (int) $row['role__id'],
                    $row['role__code'],
                    $row['role__name'],
                ),
            ),
            new Shift(
                (int) $row['shift_id'],
                $row['shift__code'],
                $row['shift__start_time'],
                $row['shift__end_time'],
                (int) $row['shift__duration_hours'],
            ),
        );
    }
}
