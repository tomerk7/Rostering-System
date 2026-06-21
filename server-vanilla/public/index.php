<?php

declare(strict_types=1);

/**
 * Front controller for the vanilla service. Captures the request and hands it to
 * the HTTP kernel, which owns route registration and the JSON error mapping (so
 * the same wiring is exercised by the API test suite). Routes are added in
 * App\Http\Kernel as features move into the vanilla pool.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Http\Kernel;
use App\Http\Request;

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

Kernel::handle(Request::capture());
