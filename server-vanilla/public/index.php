<?php

declare(strict_types=1);

/**
 * Health check for the vanilla PHP-FPM pool.
 *
 * This is intentionally NOT a router or framework — it only proves the
 * environment pipeline works end to end: nginx -> php-fpm -> Postgres. The
 * front controller, router, controllers, and repositories come in later steps.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Support\DB;

header('Content-Type: application/json');

$db = 'fail';

try {
    DB::connect()->query('SELECT 1');
    $db = 'ok';
} catch (Throwable $e) {
    $db = 'fail';
}

echo json_encode([
    'status' => 'ok',
    'app' => 'server-vanilla',
    'php' => PHP_VERSION,
    'db' => $db,
], JSON_PRETTY_PRINT);
