<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Roster;
use App\Support\DB;
use PDO;

/**
 * Reads the `rosters` table.
 */
final class RosterRepository
{
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
     * All rosters with their assignment counts, newest first (by generated_at
     * then id).
     *
     * @return list<Roster>
     */
    public function all(): array
    {
        $sql = 'SELECT r.id, r.period_start, r.status, r.generated_at, r.created_by,
                       r.created_at, r.updated_at,
                       (SELECT count(*) FROM roster_assignments ra WHERE ra.roster_id = r.id) AS assignments_count
                FROM rosters r
                ORDER BY r.generated_at DESC, r.id DESC';

        return array_map(self::fromRow(...), $this->pdo->query($sql)->fetchAll());
    }

    /**
     * A single roster by id (with its assignment count), or null when missing.
     *
     * @param int $id
     * @return Roster|null
     */
    public function find(int $id): ?Roster
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.period_start, r.status, r.generated_at, r.created_by,
                    r.created_at, r.updated_at,
                    (SELECT count(*) FROM roster_assignments ra WHERE ra.roster_id = r.id) AS assignments_count
             FROM rosters r
             WHERE r.id = ?',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : self::fromRow($row);
    }

    /**
     * Current and future rosters (period_start in the current month onward),
     * oldest id first. Used by the worker-change report write-back to scope
     * recomputation to upcoming rosters only (past rosters are preserved as
     * history).
     *
     * @return list<Roster>
     */
    public function upcoming(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id, r.period_start, r.status, r.generated_at, r.created_by,
                    r.created_at, r.updated_at,
                    (SELECT count(*) FROM roster_assignments ra WHERE ra.roster_id = r.id) AS assignments_count
             FROM rosters r
             WHERE r.period_start >= ?
             ORDER BY r.id',
        );
        $stmt->execute([gmdate('Y-m-01')]);

        return array_map(self::fromRow(...), $stmt->fetchAll());
    }

    /**
     * Delete any roster for the given period (year, month). Cascades to the
     * roster's assignments, alerts, and coverage shortages via ON DELETE CASCADE.
     *
     * @param int $year
     * @param int $month
     * @return void
     */
    public function deleteForPeriod(int $year, int $month): void
    {
        $periodStart = sprintf('%04d-%02d-01', $year, $month);
        $this->pdo->prepare('DELETE FROM rosters WHERE period_start = ?')->execute([$periodStart]);
    }

    /**
     * Insert a roster stub for a period and return it. `generated_at` is null
     * until generation completes; timestamps are set explicitly.
     *
     * @param string $periodStart
     * @param int $createdBy
     * @param string $status
     * @return Roster
     */
    public function createStub(string $periodStart, int $createdBy, string $status): Roster
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO rosters (period_start, generated_at, created_by, status, created_at, updated_at)
             VALUES (?, NULL, ?, ?, now(), now())
             RETURNING id, period_start, status, generated_at, created_by, created_at, updated_at',
        );
        $stmt->execute([$periodStart, $createdBy, $status]);
        $row = $stmt->fetch();

        return new Roster(
            (int) $row['id'],
            $row['period_start'],
            $row['status'],
            $row['generated_at'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
            null,
            $row['created_at'],
            $row['updated_at'],
        );
    }

    /**
     * Update a roster's status (and bump updated_at).
     * `$roster->update(['status' => ...])`.
     *
     * @param int $rosterId
     * @param string $status
     * @return void
     */
    public function updateStatus(int $rosterId, string $status): void
    {
        $this->pdo->prepare(
            'UPDATE rosters SET status = ?, updated_at = now() WHERE id = ?',
        )->execute([$status, $rosterId]);
    }

    /**
     * Stamp a roster as generated now (sets generated_at + updated_at).
     *
     * @param int $rosterId
     * @return void
     */
    public function markGenerated(int $rosterId): void
    {
        $this->pdo->prepare(
            'UPDATE rosters SET generated_at = now(), updated_at = now() WHERE id = ?',
        )->execute([$rosterId]);
    }

    /**
     * Delete a roster by id. Cascades to its assignments, alerts, coverage
     * shortages, and queued jobs via ON DELETE CASCADE.
     *
     * @param int $rosterId
     * @return void
     */
    public function deleteById(int $rosterId): void
    {
        $this->pdo->prepare('DELETE FROM rosters WHERE id = ?')->execute([$rosterId]);
    }

    /**
     * Whether a roster has any assignments.
     *
     * @param int $rosterId
     * @return bool
     */
    public function hasAssignments(int $rosterId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM roster_assignments WHERE roster_id = ? LIMIT 1',
        );
        $stmt->execute([$rosterId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Map a `rosters` row (with `assignments_count`) to a Roster DTO.
     *
     * @param  array<string, mixed>  $row
     * @return Roster
     */
    private static function fromRow(array $row): Roster
    {
        return new Roster(
            (int) $row['id'],
            $row['period_start'],
            $row['status'],
            $row['generated_at'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
            (int) $row['assignments_count'],
            $row['created_at'],
            $row['updated_at'],
        );
    }
}
