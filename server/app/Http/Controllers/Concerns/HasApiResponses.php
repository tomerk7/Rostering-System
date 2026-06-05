<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\JsonResponse;

trait HasApiResponses
{
    /**
     * Return a successful API response.
     *
     * @param  array<string, mixed>  $meta
     */
    protected function successResponse(
        mixed $data = null,
        string $message = 'OK',
        int $status = 200,
        array $meta = [],
    ): JsonResponse {
        return $this->response(
            success: true,
            message: $message,
            status: $status,
            data: $data,
            meta: $meta,
        );
    }

    /**
     * Return an error API response.
     *
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     */
    protected function errorResponse(
        string $message,
        int $status = 400,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        return $this->response(
            success: false,
            message: $message,
            status: $status,
            errors: $errors,
            meta: $meta,
        );
    }

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
