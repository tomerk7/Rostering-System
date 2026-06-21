<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\AuthService;
use App\Repositories\UserRepository;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use PHPUnit\Framework\TestCase;

final class AuthServiceTest extends TestCase
{
    private const ALG = 'HS256';

    private string $secret;

    protected function setUp(): void
    {
        // bootstrap.php guarantees a JWT_SECRET; read whatever it set so the
        // service and this test sign with the same key.
        $this->secret = (string) getenv('JWT_SECRET');
    }

    /**
     * decode() never touches the user repository, so inject one backed by a
     * throwaway PDO to keep this a pure unit test (the default would call
     * DB::connect() against Postgres).
     */
    private function service(): AuthService
    {
        return new AuthService(new UserRepository(new \PDO('sqlite::memory:')));
    }

    private function token(array $claims): string
    {
        return JWT::encode($claims, $this->secret, self::ALG);
    }

    public function testDecode(): void
    {
        $service = $this->service();
        $now = time();

        // A token this service's key signed decodes back to its claims.
        $token = $this->token([
            'sub' => 1,
            'email' => 'test@example.com',
            'name' => 'Test User',
            'iat' => $now,
            'exp' => $now + 3600,
        ]);

        $claims = $service->decode($token);
        $this->assertSame(1, $claims['sub']);
        $this->assertSame('test@example.com', $claims['email']);

        // A token signed with a different secret is rejected.
        // A different key, still long enough to satisfy php-jwt's HS256 key-length
        // check, so encoding succeeds but the signature won't verify against ours.
        $tampered = JWT::encode(['sub' => 1, 'exp' => $now + 3600], str_repeat('different-secret-', 3), self::ALG);
        $this->expectException(SignatureInvalidException::class);
        $service->decode($tampered);
    }

    public function testDecodeRejectsExpired(): void
    {
        // The single-test-per-method rule allows a second method when one path
        // can't read clearly inside the other; expiry needs its own exception.
        $service = $this->service();
        $expired = $this->token([
            'sub' => 1,
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]);

        $this->expectException(ExpiredException::class);
        $service->decode($expired);
    }
}
