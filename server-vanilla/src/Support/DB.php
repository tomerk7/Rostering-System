<?php

declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * Database access for the vanilla app: the shared PDO connection plus (over time)
 * query/transaction helpers. Reads the same DB_* environment variables the rest
 * of the stack uses and connects to the shared Postgres.
 */
final class DB
{
    public static function connect(): PDO
    {
        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_DATABASE') ?: 'rostering';
        $user = getenv('DB_USERNAME') ?: 'rostering';
        $pass = getenv('DB_PASSWORD') ?: '';

        return new PDO(
            "pgsql:host={$host};port={$port};dbname={$name}",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }
}
