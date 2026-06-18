<?php

declare(strict_types=1);

namespace App\Data;

/**
 * A user as read from the database. Repositories return this instead of a raw
 * array so callers get typed, self-documenting access ($user->email) instead of
 * guessing array keys. passwordHash is only populated when the lookup needs it
 * (e.g. for login); it is never exposed via toArray().
 */
final readonly class User
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?string $passwordHash = null,
    ) {}

    /**
     * Public-safe shape (no password) for API responses and JWT claims.
     *
     * @return array{id: int, name: string, email: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'email' => $this->email];
    }
}
