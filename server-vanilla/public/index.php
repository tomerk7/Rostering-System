<?php

declare(strict_types=1);

/**
 * Health check for the vanilla PHP-FPM pool.
 *
 * This is intentionally NOT a router or framework — it only proves the
 * environment pipeline works end to end: nginx -> php-fpm -> Postgres. The
 * front controller, router, controllers, and repositories come in later steps.
 */

header('Content-Type: application/json');

/**
 * Try to connect to Postgres with PDO using the same credentials as the
 * Laravel app, so a green check confirms the vanilla pool can reach the DB.
 */
function databaseStatus(): string
{
    $host = getenv('DB_HOST') ?: 'db';
    $port = getenv('DB_PORT') ?: '5432';
    $name = getenv('DB_DATABASE') ?: 'rostering';
    $user = getenv('DB_USERNAME') ?: 'rostering';
    $pass = getenv('DB_PASSWORD') ?: '';

    try {
        $pdo = new PDO(
            "pgsql:host={$host};port={$port};dbname={$name}",
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
        );
        $pdo->query('SELECT 1');

        return 'ok';
    } catch (Throwable $e) {
        return 'fail';
    }
}

echo json_encode([
    'status' => 'ok',
    'app' => 'server-vanilla',
    'php' => PHP_VERSION,
    'db' => databaseStatus(),
], JSON_PRETTY_PRINT);
