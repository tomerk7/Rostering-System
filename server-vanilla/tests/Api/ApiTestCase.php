<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Http\Kernel;
use App\Http\Request;
use Tests\TestCase;

/**
 * Base for HTTP API tests. Drives the application in-process: it builds a
 * Request and dispatches it through the same App\Http\Kernel the front
 * controller uses, capturing the JSON body and status code. Sharing the kernel
 * means routing, middleware, and the error mapping are all exercised for real,
 * while the inherited per-test transaction rollback keeps writes isolated.
 */
abstract class ApiTestCase extends TestCase
{
    /**
     * Dispatch a request and return its status, decoded JSON, and raw body.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $query
     * @return array{status: int, json: mixed, raw: string}
     */
    protected function call(string $method, string $path, array $body = [], array $headers = [], array $query = []): array
    {
        // Request::header() looks up lower-cased names, matching what capture()
        // stores from $_SERVER; normalize here so test callers can use any case.
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = $value;
        }

        $request = new Request($method, $path, $normalized, $body, $query);

        http_response_code(200);
        ob_start();
        try {
            Kernel::handle($request);
        } finally {
            $raw = (string) ob_get_clean();
        }

        return [
            'status' => http_response_code(),
            'json' => json_decode($raw, true),
            'raw' => $raw,
        ];
    }

    /**
     * Authorization header carrying a Bearer token for the seeded login user.
     *
     * @return array<string, string>
     */
    protected function authHeader(): array
    {
        return ['Authorization' => 'Bearer ' . $this->login()];
    }

    /**
     * Log in the seeded user (test@example.com / password) and return the JWT.
     */
    protected function login(string $email = 'test@example.com', string $password = 'password'): string
    {
        $response = $this->call('POST', '/api/auth/login', ['email' => $email, 'password' => $password]);

        return (string) ($response['json']['token'] ?? '');
    }

    /**
     * Create a worker through the store endpoint and return the created payload.
     * Defaults to a valid supervisor (role_id 2) available on Sunday shift 1.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function createWorker(array $overrides = []): array
    {
        $payload = array_replace([
            'full_name' => 'Dana Cohen',
            'israeli_id' => '123456782',
            'role_id' => 2,
            'is_active' => true,
            'contract' => [
                'hourly_cost' => 50,
                'min_monthly_hours' => 80,
                'max_monthly_hours' => 160,
            ],
            'availability' => [
                ['day_of_week' => 0, 'shift_id' => 1],
            ],
        ], $overrides);

        $response = $this->call('POST', '/api/workers', $payload, $this->authHeader());

        return ['status' => $response['status'], 'json' => $response['json'], 'payload' => $payload];
    }
}
