<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Auth\AuthService;
use App\Http\HttpException;
use App\Http\Request;

final class AuthController
{
    /**
     * Class constructor.
     *
     * @param AuthService $auth
     */
    public function __construct(
        private readonly AuthService $auth = new AuthService,
    ) {}

    /**
     * POST /api/auth/login — email/password → JWT.
     *
     * @return array{token: string, token_type: string, user: array{id: int, name: string, email: string}}
     */
    public function login(Request $request): array
    {
        $email = (string) $request->input('email', '');
        $password = (string) $request->input('password', '');

        if ($email === '' || $password === '') {
            throw new HttpException(422, 'Email and password are required.');
        }

        $result = $this->auth->login($email, $password);

        if (!$result) {
            throw new HttpException(401, 'Invalid credentials');
        }

        return $result;
    }

    /**
     * GET /api/auth/me — the authenticated user (JwtMiddleware attaches it).
     *
     * @return array{user: array{id: int, name: string, email: string}}
     */
    public function me(Request $request): array
    {
        /** @var array{id: int, name: string, email: string} $user */
        $user = $request->attributes['user'];

        return ['user' => $user];
    }
}
