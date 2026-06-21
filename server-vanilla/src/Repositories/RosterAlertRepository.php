<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\RosterAlert;
use App\Data\Worker;
use App\Support\DB;
use PDO;

/**
 * Reads and writes the `roster_alerts` table.
 */
final class RosterAlertRepository
{
    private const string HOURS_SHORTFALL = 'hours_shortfall';

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
     * Hours-shortfall alerts for a roster, ordered by worker_id, with the worker
     * loaded (unscoped (withTrashed), so a soft-deleted worker's name
     * still resolves; a fully missing worker falls back to the denormalized
     * worker_name).
     *
     * @param int $rosterId
     * @return list<RosterAlert>
     */
    public function hoursShortfallForRoster(int $rosterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.id, a.roster_id, a.type, a.worker_id, a.worker_name,
                    a.min_hours, a.scheduled_hours,
                    w.full_name AS worker__full_name, w.is_active::int AS worker__is_active,
                    w.deleted_at AS worker__deleted_at
             FROM roster_alerts a
             LEFT JOIN workers w ON w.israeli_id = a.worker_id
             WHERE a.roster_id = ? AND a.type = ?
             ORDER BY a.worker_id',
        );
        $stmt->execute([$rosterId, self::HOURS_SHORTFALL]);

        return array_map(static fn (array $row): RosterAlert => new RosterAlert(
            (int) $row['id'],
            (int) $row['roster_id'],
            $row['type'],
            $row['worker_id'],
            $row['worker_name'],
            (int) $row['min_hours'],
            (int) $row['scheduled_hours'],
            $row['worker__full_name'] === null ? null : new Worker(
                israeliId: $row['worker_id'],
                fullName: $row['worker__full_name'],
                isActive: (bool) $row['worker__is_active'],
                deletedAt: $row['worker__deleted_at'],
            ),
        ), $stmt->fetchAll());
    }

    /**
     * Delete all hours-shortfall alerts for a roster.
     *
     * @param int $rosterId
     * @return void
     */
    public function deleteHoursShortfallForRoster(int $rosterId): void
    {
        $this->pdo->prepare(
            'DELETE FROM roster_alerts WHERE roster_id = ? AND type = ?',
        )->execute([$rosterId, self::HOURS_SHORTFALL]);
    }

    /**
     * Delete hours-shortfall alerts for specific workers in a roster (used when
     * rebuilding only the changed workers' alerts).
     *
     * @param int $rosterId
     * @param  list<string>  $workerIds
     * @return void
     */
    public function deleteHoursShortfallForWorkers(int $rosterId, array $workerIds): void
    {
        if ($workerIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
        $this->pdo->prepare(
            "DELETE FROM roster_alerts
             WHERE roster_id = ? AND type = ? AND worker_id IN ($placeholders)",
        )->execute([$rosterId, self::HOURS_SHORTFALL, ...$workerIds]);
    }

    /**
     * Delete all alerts (any type) for the given rosters (used to clear upcoming
     * rosters' alerts on delete-all).
     *
     * @param  list<int>  $rosterIds
     * @return void
     */
    public function deleteAllForRosters(array $rosterIds): void
    {
        if ($rosterIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($rosterIds), '?'));
        $this->pdo->prepare(
            "DELETE FROM roster_alerts WHERE roster_id IN ($placeholders)",
        )->execute($rosterIds);
    }

    /**
     * Bulk-insert hours-shortfall alerts for a roster. The worker's name is
     * denormalized from the workers table (including soft-deleted) so the row
     * stays readable as history after the worker is removed.
     *
     * @param int $rosterId
     * @param  list<array{worker_id: string, min_hours: int, scheduled_hours: int}>  $rows
     * @return void
     */
    public function insertHoursShortfall(int $rosterId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $names = $this->workerNames(array_column($rows, 'worker_id'));

        $values = [];
        $params = [];

        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?, ?, ?, now(), now())';
            $params[] = $rosterId;
            $params[] = self::HOURS_SHORTFALL;
            $params[] = $row['worker_id'];
            $params[] = $names[$row['worker_id']] ?? null;
            $params[] = $row['min_hours'];
            $params[] = $row['scheduled_hours'];
        }

        $sql = 'INSERT INTO roster_alerts
                    (roster_id, type, worker_id, worker_name, min_hours, scheduled_hours, created_at, updated_at)
                VALUES ' . implode(', ', $values);

        $this->pdo->prepare($sql)->execute($params);
    }

    /**
     * Map worker id => full_name for the given ids, including soft-deleted
     * workers (Uses withTrashed for the denormalized alert name).
     *
     * @param  list<string>  $workerIds
     * @return array<string, string>
     */
    private function workerNames(array $workerIds): array
    {
        if ($workerIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($workerIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT israeli_id, full_name FROM workers WHERE israeli_id IN ($placeholders)",
        );
        $stmt->execute($workerIds);

        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $names[$row['israeli_id']] = $row['full_name'];
        }

        return $names;
    }
}
