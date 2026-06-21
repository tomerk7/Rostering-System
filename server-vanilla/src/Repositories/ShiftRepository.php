<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Shift;
use App\Support\DB;
use PDO;

/**
 * Reads the `shifts` lookup table.
 */
final class ShiftRepository
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
     * All shifts ordered by code.
     *
     * @return list<Shift>
     */
    public function all(): array
    {
        $rows = $this->pdo
            ->query('SELECT id, code, start_time, end_time, duration_hours FROM shifts ORDER BY code')
            ->fetchAll();

        return array_map(static fn (array $row): Shift => new Shift(
            (int) $row['id'],
            $row['code'],
            $row['start_time'],
            $row['end_time'],
            (int) $row['duration_hours'],
        ), $rows);
    }

    /**
     * A single shift by id, or null when missing.
     *
     * @param int $id
     * @return Shift|null
     */
    public function find(int $id): ?Shift
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, code, start_time, end_time, duration_hours FROM shifts WHERE id = ?',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : new Shift(
            (int) $row['id'],
            $row['code'],
            $row['start_time'],
            $row['end_time'],
            (int) $row['duration_hours'],
        );
    }
}
