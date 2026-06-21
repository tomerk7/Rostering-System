<?php

declare(strict_types=1);

namespace App\Auth;

use App\Data\User;
use App\Repositories\UserRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

/**
 * Email/password login that issues a signed HS256 JWT, plus token decoding.
 * Ported from the project's existing JWT auth; the secret comes from JWT_SECRET
 * instead of APP_KEY since this service has no framework config.
 */
class AuthService
{
    private const ALGORITHM = 'HS256';

    /**
     * Class constructor.
     *
     * @param UserRepository $users
     */
    public function __construct(
        private UserRepository $users = new UserRepository,
    ) {}

    /**
     * Authenticate by email/password; returns the token + user, or null on failure.
     *
     * @return array{token: string, token_type: string, user: array{id: int, name: string, email: string}}|null
     */
    public function login(string $email, string $password): ?array
    {
        $user = $this->users->findByEmail($email);

        if ($user === null || ! password_verify($password, (string) $user->passwordHash)) {
            return null;
        }

        return [
            'token' => $this->issue($user),
            'token_type' => 'Bearer',
            'user' => $user->toArray(),
        ];
    }

    /**
     * Decode and validate a token issued by this service. Throws on invalid/expired.
     *
     * @param string $token
     * @return array<string, mixed>
     */
    public function decode(string $token): array
    {
        return (array) JWT::decode($token, new Key($this->secret(), self::ALGORITHM));
    }

    /**
     * Issue a new token for the given user.
     */
    private function issue(User $user): string
    {
        $now = time();
        $ttl = (int) (getenv('JWT_TTL_SECONDS') ?: 7200);

        $payload = [
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'iat' => $now,
            'exp' => $now + $ttl,
        ];

        return JWT::encode($payload, $this->secret(), self::ALGORITHM);
    }

    /**
     * Get the secret key from the environment.
     *
     * @return string
     */
    private function secret(): string
    {
        $secret = getenv('JWT_SECRET');

        if (! is_string($secret) || $secret === '') {
            throw new RuntimeException('JWT_SECRET is not configured.');
        }

        return $secret;
    }
}
