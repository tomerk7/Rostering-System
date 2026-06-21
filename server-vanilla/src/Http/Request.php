<?php

declare(strict_types=1);

namespace App\Http;

/**
 * The incoming HTTP request, captured from PHP superglobals. Headers come from
 * $_SERVER (reliable under php-fpm); the JSON body is decoded once. Middleware
 * may stash data (e.g. the authenticated user) in $attributes for handlers.
 */
final class Request
{
    /** @var array<string, mixed> */
    public array $attributes = [];

    /**
     * Class constructor.
     *
     * @param  string  $method
     * @param  string  $path
     * @param  array<string, string>  $headers  lower-cased header names
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query  query-string params ($_GET)
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private array $headers,
        private array $body,
        private array $query = [],
        private array $files = [],
    ) {}

    /**
     * Capture the current request from PHP superglobals.
     *
     * @return self
     */
    public static function capture(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        // Some setups expose Authorization only via the (REDIRECT_)HTTP_AUTHORIZATION var.
        if (! isset($headers['authorization'])) {
            $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
            if (is_string($auth) && $auth !== '') {
                $headers['authorization'] = $auth;
            }
        }

        $body = [];
        $raw = file_get_contents('php://input') ?: '';

        if ($raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        if ($body === [] && $_POST !== []) {
            $body = $_POST;
        }

        return new self($method, $path, $headers, $body, $_GET, $_FILES);
    }

    /**
     * Get a header value by name.
     *
     * @param string $name
     * @return string|null
     */
    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * The Bearer token from the Authorization header, or null.
     *
     * @return string|null
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization');
        if (! is_string($header) || $header === '') {
            return null;
        }
        if (! preg_match('/^Bearer\s+(?<token>.+)$/i', $header, $matches)) {
            return null;
        }
        $token = trim($matches['token']);

        return $token !== '' ? $token : null;
    }

    /**
     * Get a value from the request body by key.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * The full decoded request body.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * An uploaded file by field name ($_FILES entry), or null.
     *
     * @param string $key
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        return $this->files[$key] ?? null;
    }

    /**
     * Get a query-string param by key.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function query(string $key, mixed $default = null): mixed
    {
        $value = $this->query[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * Whether a query-string param is present (any value, including empty).
     *
     * @param string $key
     * @return bool
     */
    public function hasQuery(string $key): bool
    {
        return array_key_exists($key, $this->query);
    }
}
