<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private function request(): Request
    {
        return new Request(
            method: 'POST',
            path: '/api/workers',
            headers: ['authorization' => 'Bearer abc.def.ghi', 'x-custom' => 'val'],
            body: ['name' => 'Dana', 'role' => 'supervisor'],
            query: ['page' => '2', 'empty' => ''],
        );
    }

    public function testInput(): void
    {
        $req = $this->request();
        $this->assertSame('Dana', $req->input('name'));
        // Missing key falls back to the provided default.
        $this->assertSame('fallback', $req->input('missing', 'fallback'));
        $this->assertNull($req->input('missing'));
    }

    public function testAll(): void
    {
        $this->assertSame(['name' => 'Dana', 'role' => 'supervisor'], $this->request()->all());
    }

    public function testQuery(): void
    {
        $req = $this->request();
        $this->assertSame('2', $req->query('page'));
        // Empty-string params are treated as absent and fall back to the default.
        $this->assertSame('def', $req->query('empty', 'def'));
        $this->assertNull($req->query('nope'));
    }

    public function testHeader(): void
    {
        $req = $this->request();
        // Header lookup is case-insensitive.
        $this->assertSame('val', $req->header('X-Custom'));
        $this->assertNull($req->header('missing'));
    }

    public function testBearerToken(): void
    {
        $this->assertSame('abc.def.ghi', $this->request()->bearerToken());

        // No Authorization header → null.
        $without = new Request('GET', '/', [], [], []);
        $this->assertNull($without->bearerToken());

        // Non-Bearer scheme → null.
        $basic = new Request('GET', '/', ['authorization' => 'Basic xyz'], [], []);
        $this->assertNull($basic->bearerToken());
    }

    public function testHasQuery(): void
    {
        $req = $this->request();
        // Present even when empty-valued...
        $this->assertTrue($req->hasQuery('empty'));
        $this->assertTrue($req->hasQuery('page'));
        // ...absent otherwise.
        $this->assertFalse($req->hasQuery('nope'));
    }
}
