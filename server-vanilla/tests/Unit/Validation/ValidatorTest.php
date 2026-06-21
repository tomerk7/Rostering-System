<?php

declare(strict_types=1);

namespace Tests\Unit\Validation;

use App\Validation\ValidationException;
use App\Validation\Validator;
use PDO;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    /**
     * A throwaway in-memory PDO so the constructor never reaches the real
     * database. None of the rules exercised here (required/integer/between/in/
     * boolean) query a table, so the connection is never actually used.
     */
    private function pdo(): PDO
    {
        return new PDO('sqlite::memory:');
    }

    public function testValidate(): void
    {
        $data = [
            'role' => 'supervisor',
            'hours' => '40',
            'active' => '1',
        ];

        $validator = new Validator($data, $this->pdo());

        // Valid input passes and returns the original data unchanged.
        $returned = $validator->validate([
            'role' => ['required', 'in:supervisor,screener,general_guard'],
            'hours' => ['required', 'integer', 'between:0,100'],
            'active' => ['boolean'],
        ]);
        $this->assertSame($data, $returned);

        // Invalid input throws, and the summary message counts the extra errors.
        try {
            (new Validator(
                ['role' => 'pilot', 'hours' => 'abc'],
                $this->pdo(),
            ))->validate([
                'role' => ['required', 'in:supervisor,screener'],
                'hours' => ['required', 'integer'],
                'missing' => ['required'],
            ]);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $errors = $e->errors;
            $this->assertArrayHasKey('role', $errors);
            $this->assertArrayHasKey('hours', $errors);
            $this->assertArrayHasKey('missing', $errors);
            // First message + "(and N more errors)" summary.
            $this->assertStringContainsString('more error', $e->getMessage());
        }
    }

    public function testCollect(): void
    {
        // collect() returns the field => messages map without throwing.
        $clean = (new Validator(['hours' => 50], $this->pdo()))
            ->collect(['hours' => ['required', 'integer', 'between:0,100']]);
        $this->assertSame([], $clean);

        $errors = (new Validator(['hours' => 500, 'flag' => 'maybe'], $this->pdo()))
            ->collect([
                'hours' => ['required', 'integer', 'between:0,100'],
                'flag' => ['boolean'],
            ]);

        $this->assertArrayHasKey('hours', $errors);
        $this->assertArrayHasKey('flag', $errors);
        $this->assertStringContainsString('between 0 and 100', $errors['hours'][0]);
        $this->assertStringContainsString('true or false', $errors['flag'][0]);
    }
}
