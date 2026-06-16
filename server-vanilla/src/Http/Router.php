<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A tiny method+path router. Patterns may contain `{param}` placeholders, which
 * are matched and passed to the handler. Per-route middleware run before the
 * handler and throw HttpException to short-circuit (e.g. auth failures).
 *
 * Handlers receive (Request $req, array $params) and return data to be JSON
 * encoded (200), or throw HttpException for any other status.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable, middleware: list<callable>}> */
    private array $routes = [];

    /**
     * Register a GET route.
     *
     * @param string $pattern
     * @param callable $handler
     * @param array $middleware
     * @return void
     */
    public function get(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /**
     * Register a POST route.
     *
     * @param string $pattern
     * @param callable $handler
     * @param array $middleware
     * @return void
     */
    public function post(string $pattern, callable $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * Register a route.
     *
     * @param string $method
     * @param string $pattern
     * @param callable $handler
     * @param  list<callable>  $middleware
     */
    public function add(string $method, string $pattern, callable $handler, array $middleware = []): void
    {
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?<$1>[^/]+)', $pattern) . '$#';
        $this->routes[] = ['method' => $method, 'regex' => $regex, 'handler' => $handler, 'middleware' => $middleware];
    }

    /**
     * Match and run a route. Throws HttpException(404) when nothing matches.
     *
     * @param Request $request
     * @return void
     */
    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }
            if (! preg_match($route['regex'], $request->path, $matches)) {
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            foreach ($route['middleware'] as $middleware) {
                $middleware($request);
            }

            $data = ($route['handler'])($request, $params);
            Response::json($data);

            return;
        }

        throw new HttpException(404, 'Not found');
    }
}
