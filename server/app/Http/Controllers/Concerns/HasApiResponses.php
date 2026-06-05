<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

trait HasApiResponses
{
    /**
     * Return a normalized API response envelope.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     */
    protected function response(
        bool $success,
        string $message,
        int $status,
        mixed $data = null,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => $success,
            'message' => $message,
            'data' => $data,
            'errors' => $errors,
            'meta' => $meta,
        ];

        return response()->json($payload, $status);
    }
}
