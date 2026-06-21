<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal RFC 4122 v4 UUID generator (no extension dependency).
 */
final class Uuid
{
    /**
     * Generate a random v4 UUID.
     *
     * @return string
     * @throws \Random\RandomException
     */
    public static function v4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
