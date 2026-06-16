<?php

declare(strict_types=1);

namespace App\Http;

use RuntimeException;

/**
 * An exception carrying an HTTP status, mapped to a JSON error response by the
 * front controller. Lets controllers/middleware fail with `throw new
 * HttpException(401, '...')` instead of emitting responses inline.
 */
final class HttpException extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
