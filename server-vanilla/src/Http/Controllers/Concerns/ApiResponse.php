<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

/**
 * Builds the normalized API response the SPA expects:
 * `{ success, message, data, errors, meta }`. Mirrors the Laravel
 * `HasApiResponses` trait so migrated routes stay wire-compatible.
 */
trait ApiResponse
{
    /**
     * Build a success/failure response. The Router JSON-encodes the return value;
     * set the HTTP status by throwing HttpException for non-200 paths.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     * @return array{success: bool, message: string, data: mixed, errors: array<string, mixed>, meta: array<string, mixed>}
     */
    protected function response(
        bool $success,
        string $message,
        mixed $data = null,
        array $errors = [],
        array $meta = [],
    ): array {
        return [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
        ];
    }
}
