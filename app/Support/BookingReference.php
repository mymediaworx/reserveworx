<?php

namespace App\Support;

/**
 * The short reference customers see and read out: the last 8 characters of
 * the booking ULID, upper case. The full ID stays in URLs and staff screens.
 */
final class BookingReference
{
    public const LENGTH = 8;

    public static function short(string $bookingId): string
    {
        return strtoupper(substr($bookingId, -self::LENGTH));
    }

    public static function isShort(string $reference): bool
    {
        return preg_match('/^[0-9A-Za-z]{'.self::LENGTH.'}$/', $reference) === 1;
    }
}
