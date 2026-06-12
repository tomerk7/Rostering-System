<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Normalized JSON 404 envelope for API routes.
 */
final class ApiNotFoundResponse
{
    /**
     * Build a JSON 404 from a model-not-found or wrapped not-found exception.
     */
    public static function from(Throwable $exception): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => self::message($exception),
            'data' => null,
            'errors' => [],
            'meta' => [],
        ], 404);
    }

    /**
     * Resolve a user-facing message for a missing Eloquent model.
     */
    public static function message(Throwable $exception): string
    {
        $modelNotFound = self::modelNotFoundException($exception);

        if ($modelNotFound === null) {
            return 'Resource not found.';
        }

        return match (class_basename($modelNotFound->getModel())) {
            'Roster' => 'Roster not found.',
            'Worker' => 'Worker not found.',
            default => 'Resource not found.',
        };
    }

    private static function modelNotFoundException(Throwable $exception): ?ModelNotFoundException
    {
        if ($exception instanceof ModelNotFoundException) {
            return $exception;
        }

        $previous = $exception->getPrevious();

        return $previous instanceof ModelNotFoundException ? $previous : null;
    }
}
