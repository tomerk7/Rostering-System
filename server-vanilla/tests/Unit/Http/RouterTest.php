<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\HttpException;
use App\Http\Request;
use App\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function request(string $method, string $path): Request
    {
        return new Request($method, $path, [], [], []);
    }

    /**
     * Run dispatch with output buffered (it echoes the JSON body) and return the
     * captured response string.
     */
    private function capture(Router $router, Request $request): string
    {
        ob_start();
        try {
            $router->dispatch($request);
        } finally {
            $body = ob_get_clean();
        }

        return $body;
    }

    public function testAdd(): void
    {
        $router = new Router();
        $router->add('GET', '/ping', static fn (): array => ['ok' => true]);

        // A registered route is matched on method + path and its return JSON-encoded.
        $this->assertSame('{"ok":true}', $this->capture($router, $this->request('GET', '/ping')));
    }

    public function testGet(): void
    {
        $router = new Router();
        $seen = null;

        // The verb helpers all delegate to add(); get() is the representative.
        $router->get('/workers/{id}', function (Request $req, array $params) use (&$seen): array {
            $seen = $params;

            return ['id' => $params['id']];
        });

        $body = $this->capture($router, $this->request('GET', '/workers/42'));

        // {param} placeholders are extracted and passed to the handler.
        $this->assertSame(['id' => '42'], $seen);
        $this->assertSame('{"id":"42"}', $body);
    }

    public function testDispatch(): void
    {
        $router = new Router();
        $middlewareRan = false;

        $router->get(
            '/rosters/{roster}/stats',
            static fn (Request $req, array $params): array => ['roster' => $params['roster']],
            [static function (Request $req) use (&$middlewareRan): void {
                $middlewareRan = true;
            }],
        );

        $body = $this->capture($router, $this->request('GET', '/rosters/7/stats'));

        // Middleware runs before the handler; the named param is extracted.
        $this->assertTrue($middlewareRan);
        $this->assertSame('{"roster":"7"}', $body);

        // Right path but wrong method does not match → 404.
        try {
            $router->dispatch($this->request('POST', '/rosters/7/stats'));
            $this->fail('Expected HttpException was not thrown.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->status);
        }
    }
}
