<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\Role;
use App\Support\DB;
use PDO;

/**
 * Reads the `roles` lookup table.
 */
final class RoleRepository
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
     * All roles ordered by display name.
     *
     * @return list<Role>
     */
    public function all(): array
    {
        $rows = $this->pdo
            ->query('SELECT id, code, name FROM roles ORDER BY name')
            ->fetchAll();

        return array_map(
            static fn (array $row): Role => new Role((int) $row['id'], $row['code'], $row['name']),
            $rows,
        );
    }
}
