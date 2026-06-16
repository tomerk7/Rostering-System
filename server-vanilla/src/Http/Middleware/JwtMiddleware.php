<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Auth\AuthService;
use App\Http\HttpException;
use App\Http\Request;
use App\Repositories\UserRepository;
use Throwable;

/**
 * Authenticates a request from its Bearer JWT: validates the token, loads the
 * user named by `sub`, and stashes it on the request. Throws HttpException so
 * the front controller renders the JSON 401. Mirrors the project's original
 * JwtMiddleware.
 */
final class JwtMiddleware
{
    /**
     * Class constructor.
     *
     * @param AuthService $auth
     * @param UserRepository $users
     */
    public function __construct(
        private readonly AuthService $auth = new AuthService,
        private readonly UserRepository $users = new UserRepository,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @return void
     */
    public function __invoke(Request $request): void
    {
        $token = $request->bearerToken();

        if (!$token) {
            throw new HttpException(401, 'Missing Bearer token');
        }

        try {
            $payload = $this->auth->decode($token);
        } catch (Throwable) {
            throw new HttpException(401, 'Invalid or expired token');
        }

        $sub = $payload['sub'] ?? null;

        if (! is_int($sub) && ! (is_string($sub) && ctype_digit($sub))) {
            throw new HttpException(401, 'Invalid token payload');
        }

        $user = $this->users->findById((int) $sub);

        if (!$user) {
            throw new HttpException(401, 'User not found');
        }

        $request->attributes['user'] = $user;
    }
}
