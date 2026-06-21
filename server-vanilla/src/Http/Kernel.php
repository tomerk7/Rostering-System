<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\RosterController;
use App\Http\Controllers\WorkerController;
use App\Http\Middleware\JwtMiddleware;
use App\Support\DB;
use App\Validation\ValidationException;
use Throwable;

/**
 * The HTTP application kernel: the single place routes are registered and a
 * request is dispatched with the standard error mapping. The front controller
 * (public/index.php) and the API test suite both build the router and handle
 * requests through here, so they stay in lockstep.
 */
final class Kernel
{
    /**
     * Build the router with every API route registered.
     */
    public static function router(): Router
    {
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

        // Auth endpoints.
        $auth = new AuthController;
        $router->post('/api/auth/login', [$auth, 'login']);
        $router->get('/api/auth/me', [$auth, 'me'], [new JwtMiddleware]);

        // Workers JWT is re-added here now that the route
        // lives in the vanilla pool.
        $workers = new WorkerController;
        $jwt = [new JwtMiddleware];
        $router->get('/api/workers/reference-data', [$workers, 'referenceData'], $jwt);
        $router->post('/api/workers/delete-all', [$workers, 'deleteAll'], $jwt);
        $router->post('/api/workers/restore-all', [$workers, 'restoreAll'], $jwt);
        $router->get('/api/workers', [$workers, 'index'], $jwt);
        $router->post('/api/workers', [$workers, 'store'], $jwt);
        $router->get('/api/workers/{worker}', [$workers, 'show'], $jwt);
        $router->put('/api/workers/{worker}', [$workers, 'update'], $jwt);
        $router->patch('/api/workers/{worker}', [$workers, 'update'], $jwt);
        $router->delete('/api/workers/{worker}', [$workers, 'destroy'], $jwt);
        $router->post('/api/workers/{worker}/deactivate', [$workers, 'deactivate'], $jwt);
        $router->post('/api/workers/{worker}/restore', [$workers, 'restore'], $jwt);

        // CSV import/export (async via the worker daemon). Register the literal
        // import/sample before import/{import} so it wins over the param route.
        $router->post('/api/workers/import', [$workers, 'import'], $jwt);
        $router->get('/api/workers/import/sample', [$workers, 'importSample'], $jwt);
        $router->get('/api/workers/import/{import}', [$workers, 'importStatus'], $jwt);
        $router->post('/api/workers/export', [$workers, 'export'], $jwt);
        $router->get('/api/workers/export/{export}/download', [$workers, 'exportDownload'], $jwt);
        $router->get('/api/workers/export/{export}', [$workers, 'exportStatus'], $jwt);

        // Rosters. Reads first; index is the entry point.
        $rosters = new RosterController;
        $router->get('/api/rosters', [$rosters, 'index'], $jwt);
        $router->post('/api/rosters', [$rosters, 'store'], $jwt);
        // Literal /benchmark before the {roster} param routes so it wins.
        $router->post('/api/rosters/benchmark', [$rosters, 'benchmark'], $jwt);
        $router->post('/api/rosters/{roster}/regenerate', [$rosters, 'regenerate'], $jwt);
        $router->get('/api/rosters/{roster}/assignments', [$rosters, 'assignments'], $jwt);
        $router->post('/api/rosters/{roster}/assignments', [$rosters, 'storeAssignment'], $jwt);
        $router->delete('/api/rosters/{roster}/assignments/{assignment}', [$rosters, 'destroyAssignment'], $jwt);
        $router->get('/api/rosters/{roster}/stats', [$rosters, 'stats'], $jwt);
        // Roster CSV export (async via the worker daemon). Register the download literal
        // before the status param route so it wins.
        $router->post('/api/rosters/{roster}/export', [$rosters, 'export'], $jwt);
        $router->get('/api/rosters/{roster}/export/{export}/download', [$rosters, 'exportDownload'], $jwt);
        $router->get('/api/rosters/{roster}/export/{export}', [$rosters, 'exportStatus'], $jwt);
        $router->get('/api/rosters/{roster}', [$rosters, 'show'], $jwt);
        $router->delete('/api/rosters/{roster}', [$rosters, 'destroy'], $jwt);

        return $router;
    }

    /**
     * Dispatch a request through the router, mapping the known exception types to
     * their JSON error responses (Standard validation body included).
     */
    public static function handle(Request $request): void
    {
        try {
            self::router()->dispatch($request);
        } catch (ValidationException $e) {
            // Standard validation body: { message, errors }.
            Response::json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        } catch (HttpException $e) {
            Response::json(['message' => $e->getMessage()], $e->status);
        } catch (Throwable $e) {
            Response::json(['message' => 'Server error'], 500);
        }
    }
}
