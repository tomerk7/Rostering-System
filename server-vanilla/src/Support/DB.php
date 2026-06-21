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
    /** Shared connection, reused across repositories within a request. */
    private static ?PDO $connection = null;

    public static function connect(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = getenv('DB_HOST') ?: 'db';
        $port = getenv('DB_PORT') ?: '5432';
        $name = getenv('DB_DATABASE') ?: 'rostering';
        $user = getenv('DB_USERNAME') ?: 'rostering';
        $pass = getenv('DB_PASSWORD') ?: '';

        return self::$connection = new PDO(
            "pgsql:host={$host};port={$port};dbname={$name}",
            $user,
            $pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );
    }

    /**
     * Run a callback inside a transaction on the shared connection, committing on
     * success and rolling back if it throws.
     *
     * Reentrant: a nested call joins the outer transaction (the outermost begin
     * governs the single commit/rollback), so services that each wrap their own
     * work compose without "active transaction" errors. This matches
     * nested-transaction semantics for our use (one logical unit of work).
     *
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function transaction(callable $callback): mixed
    {
        $pdo = self::connect();

        if ($pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();

            throw $e;
        }
    }
}
