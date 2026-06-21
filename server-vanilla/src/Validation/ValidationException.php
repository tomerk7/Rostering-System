<?php

declare(strict_types=1);

namespace App\Validation;

use RuntimeException;

/**
 * Thrown when request validation fails. Rendered by the front controller as a
 * Standard 422 body: `{ "message":.., "errors": { field: [msg] } }`.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(string $message, public readonly array $errors)
    {
        parent::__construct($message);
    }
}
