<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\CoverageShortage;
use App\Data\Role;
use App\Data\Shift;
use App\Support\DB;
use PDO;

/**
 * Reads and writes the `coverage_shortages` table.
 */
final class CoverageShortageRepository
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
     * Coverage shortages for a roster, ordered by work_date, shift_id, role_id,
     * each with its shift and role loaded.
     *
     * @param int $rosterId
     * @return list<CoverageShortage>
     */
    public function forRoster(int $rosterId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cs.id, cs.roster_id, cs.work_date, cs.shift_id, cs.role_id,
                    cs.required_count, cs.assigned_count,
                    s.code AS shift__code, s.start_time AS shift__start_time,
                    s.end_time AS shift__end_time, s.duration_hours AS shift__duration_hours,
                    r.id AS role__id, r.code AS role__code, r.name AS role__name
             FROM coverage_shortages cs
             LEFT JOIN shifts s ON s.id = cs.shift_id
             LEFT JOIN roles r ON r.id = cs.role_id
             WHERE cs.roster_id = ?
             ORDER BY cs.work_date, cs.shift_id, cs.role_id',
        );
        $stmt->execute([$rosterId]);

        return array_map(static fn (array $row): CoverageShortage => new CoverageShortage(
            (int) $row['id'],
            (int) $row['roster_id'],
            substr((string) $row['work_date'], 0, 10),
            (int) $row['shift_id'],
            (int) $row['role_id'],
            (int) $row['required_count'],
            (int) $row['assigned_count'],
            $row['shift__code'] === null ? null : new Shift(
                (int) $row['shift_id'],
                $row['shift__code'],
                $row['shift__start_time'],
                $row['shift__end_time'],
                (int) $row['shift__duration_hours'],
            ),
            $row['role__id'] === null ? null : new Role(
                (int) $row['role__id'],
                $row['role__code'],
                $row['role__name'],
            ),
        ), $stmt->fetchAll());
    }

    /**
     * Whether a roster has any coverage shortages.
     * `$roster->coverageShortages()->exists()` (the export guard).
     *
     * @param int $rosterId
     * @return bool
     */
    public function existsForRoster(int $rosterId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM coverage_shortages WHERE roster_id = ? LIMIT 1');
        $stmt->execute([$rosterId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Delete all coverage shortages for a roster.
     *
     * @param int $rosterId
     * @return void
     */
    public function deleteForRoster(int $rosterId): void
    {
        $this->pdo->prepare('DELETE FROM coverage_shortages WHERE roster_id = ?')->execute([$rosterId]);
    }

    /**
     * Delete the coverage shortage for a single (date, shift, role) cell in a
     * roster, if one exists. Used by the incremental per-assignment refresh,
     * which recomputes only the cell an add/delete touched.
     *
     * @param int $rosterId
     * @param string $workDate
     * @param int $shiftId
     * @param int $roleId
     * @return void
     */
    public function deleteCell(int $rosterId, string $workDate, int $shiftId, int $roleId): void
    {
        $this->pdo->prepare(
            'DELETE FROM coverage_shortages
             WHERE roster_id = ? AND work_date = ? AND shift_id = ? AND role_id = ?',
        )->execute([$rosterId, $workDate, $shiftId, $roleId]);
    }

    /**
     * Bulk-insert coverage shortages for a roster.
     *
     * @param int $rosterId
     * @param  list<array{work_date: string, shift_id: int, role_id: int, required: int, assigned: int}>  $rows
     * @return void
     */
    public function insertMany(int $rosterId, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $values = [];
        $params = [];

        foreach ($rows as $row) {
            $values[] = '(?, ?, ?, ?, ?, ?, now(), now())';
            $params[] = $rosterId;
            $params[] = $row['work_date'];
            $params[] = $row['shift_id'];
            $params[] = $row['role_id'];
            $params[] = $row['required'];
            $params[] = $row['assigned'];
        }

        $sql = 'INSERT INTO coverage_shortages
                    (roster_id, work_date, shift_id, role_id, required_count, assigned_count, created_at, updated_at)
                VALUES ' . implode(', ', $values);

        $this->pdo->prepare($sql)->execute($params);
    }
}
