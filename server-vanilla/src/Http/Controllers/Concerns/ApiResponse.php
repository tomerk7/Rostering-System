<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\JsonResponse;

/**
 * Builds the normalized API response the SPA expects:
 * `HasApiResponses` trait so migrated routes stay wire-compatible.
 */
trait ApiResponse
{
    /**
     * Build a success/failure response with an HTTP status. The router emits the
     * returned JsonResponse with that status.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     */
    protected function response(
        bool $success,
        string $message,
        int $status = 200,
        mixed $data = null,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        return new JsonResponse([
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
        ], $status);
    }
}
