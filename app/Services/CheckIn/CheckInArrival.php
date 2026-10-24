<?php

namespace App\Services\CheckIn;

use App\Models\Booking;

/**
 * The result of opening a check-in link. The booking is unchanged when blocked or refused.
 */
final class CheckInArrival
{
    public function __construct(
        public Booking $booking,
        public bool $checkedInNow,
        public bool $already,
        public bool $blocked,
        public bool $refused,
        public WaiverReadiness $waivers,
        public ?int $balanceDueCents,
        public string $currency,
    ) {}
}
