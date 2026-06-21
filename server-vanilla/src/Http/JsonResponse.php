<?php

declare(strict_types=1);

namespace App\Http;

/**
 * A handler return value that carries an explicit HTTP status. The router emits
 * it with that status; handlers that just return data get a 200 by default.
 */
final readonly class JsonResponse
{
    /**
     * Class constructor.
     * 
     * @param mixed $data
     * @param int $status
     */
    public function __construct(
        public mixed $data,
        public int $status = 200,
    ) {}
}
