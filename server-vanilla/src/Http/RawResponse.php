<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A non-JSON handler return value (e.g. a CSV download). The router emits the
 * raw body with the given content type, status, and extra headers.
 */
final readonly class RawResponse
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $body,
        public string $contentType = 'text/plain',
        public int $status = 200,
        public array $headers = [],
    ) {}
}
