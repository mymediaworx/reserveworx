<?php

namespace App\Support;

/**
 * Reads a PHP memory_limit value. The shared-host floor is 128M, the lower
 * figure reported for this account.
 */
final class MemoryLimit
{
    public const FLOOR_BYTES = 128 * 1024 * 1024;

    public static function bytes(string $value): ?int
    {
        $trimmed = trim($value);

        if (preg_match('/^(-1|\d+)([KMG])?$/i', $trimmed, $matches) !== 1) {
            return null;
        }

        if ($matches[1] === '-1') {
            return -1;
        }

        $amount = (int) $matches[1];

        return match (strtoupper($matches[2] ?? '')) {
            'G' => $amount * 1024 * 1024 * 1024,
            'M' => $amount * 1024 * 1024,
            'K' => $amount * 1024,
            default => $amount,
        };
    }

    public static function meetsSharedFloor(string $value): bool
    {
        $bytes = self::bytes($value);

        return $bytes === -1 || ($bytes !== null && $bytes >= self::FLOOR_BYTES);
    }
}
