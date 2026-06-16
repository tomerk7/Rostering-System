<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Minimal JSON responder.
 */
final class Response
{
    /**
     * Send a JSON response.
     *
     * @param mixed $data
     * @param int $status
     * @return void
     */
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }
}
