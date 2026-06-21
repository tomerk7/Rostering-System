<?php

declare(strict_types=1);

namespace Tests\Api;

final class AuthControllerTest extends ApiTestCase
{
    public function testLogin(): void
    {
        // Good credentials → 200 with a Bearer token and the user.
        $ok = $this->call('POST', '/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password',
        ]);
        $this->assertSame(200, $ok['status']);
        $this->assertSame('Bearer', $ok['json']['token_type']);
        $this->assertNotEmpty($ok['json']['token']);
        $this->assertSame('test@example.com', $ok['json']['user']['email']);

        // Wrong password → 401.
        $bad = $this->call('POST', '/api/auth/login', [
            'email' => 'test@example.com',
            'password' => 'wrong',
        ]);
        $this->assertSame(401, $bad['status']);

        // Missing fields → 422 before any credential check.
        $missing = $this->call('POST', '/api/auth/login', ['email' => 'test@example.com']);
        $this->assertSame(422, $missing['status']);
    }

    public function testMe(): void
    {
        // With a valid token → 200 and the current user.
        $authed = $this->call('GET', '/api/auth/me', [], $this->authHeader());
        $this->assertSame(200, $authed['status']);
        $this->assertSame('test@example.com', $authed['json']['user']['email']);

        // Without a token the JwtMiddleware rejects with 401.
        $anon = $this->call('GET', '/api/auth/me');
        $this->assertSame(401, $anon['status']);
    }
}
