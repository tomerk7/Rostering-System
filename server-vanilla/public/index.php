<?php

declare(strict_types=1);

/**
 * Front controller for the vanilla service. Captures the request, dispatches it
 * through the router, and renders the result (or a JSON error). Routes are added
 * here as features migrate from Laravel to the vanilla pool.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Http\Controllers\AuthController;
use App\Http\Controllers\WorkerController;
use App\Http\HttpException;
use App\Http\Middleware\JwtMiddleware;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\Support\DB;

// CORS: the SPA is served from a different origin (e.g. http://localhost:5173)
// than this API (:8000), so browsers send a preflight OPTIONS and require these
// headers. Auth is a Bearer token (no cookies), so reflecting the origin without
// credentials is sufficient. Tighten the allowed origin for production.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
}

// Short-circuit the preflight before routing (the router only knows real verbs).
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);

    return;
}

$router = new Router;

// Liveness: nginx -> php-fpm -> Postgres.
$router->get('/__vanilla/health', static function (): array {
    $db = 'fail';
    try {
        DB::connect()->query('SELECT 1');
        $db = 'ok';
    } catch (Throwable) {
        $db = 'fail';
    }

    return ['status' => 'ok', 'app' => 'server-vanilla', 'php' => PHP_VERSION, 'db' => $db];
});

// Auth (the single auth system; Laravel routes are unauthenticated for now).
$auth = new AuthController;
$router->post('/api/auth/login', [$auth, 'login']);
$router->get('/api/auth/me', [$auth, 'me'], [new JwtMiddleware]);

// Workers (migrating from Laravel). JWT is re-added here now that the route
// lives in the vanilla pool — the Laravel group runs unauthenticated.
$workers = new WorkerController;
$router->get('/api/workers/reference-data', [$workers, 'referenceData'], [new JwtMiddleware]);

try {
    $router->dispatch(Request::capture());
} catch (HttpException $e) {
    Response::json(['message' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    Response::json(['message' => 'Server error'], 500);
}
