<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Role;
use App\Data\ShiftRoleRequirement;
use App\Support\DB;
use PDO;

/**
 * Reads the `shift_role_requirements` table (per-shift role staffing demand).
 */
final class ShiftRoleRequirementRepository
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
     * All requirements ordered by shift then role. Pass $withRole to eager-load
     * the related Role (a join); otherwise each row's role stays null.
     *
     * @return list<ShiftRoleRequirement>
     */
    public function all(bool $withRole = false): array
    {
        if ($withRole) {
            $sql = 'SELECT srr.shift_id, srr.role_id, srr.required_count,
                           r.id AS role__id, r.code AS role__code, r.name AS role__name
                    FROM shift_role_requirements srr
                    LEFT JOIN roles r ON r.id = srr.role_id
                    ORDER BY srr.shift_id, srr.role_id';
        } else {
            $sql = 'SELECT shift_id, role_id, required_count
                    FROM shift_role_requirements
                    ORDER BY shift_id, role_id';
        }

        $rows = $this->pdo->query($sql)->fetchAll();

        return array_map(static fn (array $row): ShiftRoleRequirement => new ShiftRoleRequirement(
            (int) $row['shift_id'],
            (int) $row['role_id'],
            (int) $row['required_count'],
            ($withRole && $row['role__id'] !== null)
                ? new Role((int) $row['role__id'], $row['role__code'], $row['role__name'])
                : null,
        ), $rows);
    }

    /**
     * The required headcount for a (shift, role) pair, or 0 when there is no
     * demand row — i.e. that role is not staffed on that shift at all.
     *
     * @param int $shiftId
     * @param int $roleId
     * @return int
     */
    public function requiredCount(int $shiftId, int $roleId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT required_count FROM shift_role_requirements WHERE shift_id = ? AND role_id = ?',
        );
        $stmt->execute([$shiftId, $roleId]);
        $value = $stmt->fetchColumn();

        return $value === false ? 0 : (int) $value;
    }
}
