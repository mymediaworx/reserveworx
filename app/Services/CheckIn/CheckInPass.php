<?php

namespace App\Services\CheckIn;

use App\Enums\BookingStatus;
use App\Models\Booking;

/**
 * The QR a confirmed booking shows to staff. Held and cancelled bookings do not get one.
 */
final class CheckInPass
{
    public function __construct(
        private readonly CheckInLink $links,
        private readonly QrSvg $qr,
    ) {}

    public function url(Booking $booking): ?string
    {
        if ($booking->status !== BookingStatus::Confirmed) {
            return null;
        }

        return $this->links->url($booking);
    }

    public function svg(Booking $booking): ?string
    {
        $url = $this->url($booking);

        if ($url === null) {
            return null;
        }

        return $this->qr->render($url);
    }
}
