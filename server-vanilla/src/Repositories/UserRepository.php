<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Data\User;
use App\Support\DB;
use PDO;

/**
 * Reads users from the shared Postgres `users` table, returning User objects.
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
     * Find a user by email, including the password hash (for login).
     */
    public function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email, password FROM users WHERE email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return new User((int) $row['id'], $row['name'], $row['email'], $row['password']);
    }

    /**
     * Find a user by id (no password hash).
     */
    public function findById(int $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT id, name, email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        if ($row === false) {
            return null;
        }

        return new User((int) $row['id'], $row['name'], $row['email']);
    }
}
