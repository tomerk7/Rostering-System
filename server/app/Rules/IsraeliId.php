<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

final class IsraeliId implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^\d{9}$/', $value)) {
            $fail('The :attribute must be a valid 9-digit Israeli ID.');

            return;
        }

        $sum = 0;

        for ($index = 0; $index < 9; $index++) {
            $product = (int) $value[$index] * ($index % 2 === 0 ? 1 : 2);
            $sum += intdiv($product, 10) + ($product % 10);
        }

        if ($sum % 10 !== 0) {
            $fail('The :attribute must pass the Israeli ID checksum.');
        }
    }
}
