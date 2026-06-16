<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Support\DB;
use PDO;

/**
 * Reads users from the shared Postgres `users` table.
 */
final class UserRepository
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
     * Find a user by email.
     *
     * @param string $email
     * @return array{id: int, name: string, email: string, password: string}|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, password FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->cast($row);
    }

    /**
     * Find a user by ID.
     *
     * @param int $id
     * @return array{id: int, name: string, email: string}|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email']];
    }

    /**
     * Cast a database row to a user array.
     *
     * @param  array<string, mixed>  $row
     * @return array{id: int, name: string, email: string, password: string}
     */
    private function cast(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'email' => $row['email'],
            'password' => $row['password'],
        ];
    }
}
