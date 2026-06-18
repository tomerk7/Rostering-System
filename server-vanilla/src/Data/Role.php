<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A role as read from the `roles` table.
 */
final readonly class Role
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
    ) {}

    /**
     * @return array{id: int, code: string, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name];
    }
}
