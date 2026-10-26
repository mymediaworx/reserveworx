<?php

namespace App\Services\Waivers;

use App\Models\Booking;
use App\Services\CheckIn\WaiverGate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * The customer's signed link to the waiver page. It is offered only while a
 * current waiver still needs a signature, and lasts until a day after the visit.
 */
final class WaiverLinks
{
    public function __construct(private readonly WaiverGate $gate) {}

    public function needsSigning(Booking $booking): bool
    {
        $status = $this->gate->status($booking);

        return $status->required && ! $status->complete;
    }

    public function url(Booking $booking): string
    {
        $end = CarbonImmutable::instance($booking->ends_at)->addDay();
        $minimum = CarbonImmutable::now()->addDay();

        return URL::temporarySignedRoute('waivers.sign', $end->greaterThan($minimum) ? $end : $minimum, ['booking' => $booking->id]);
    }

    public function urlIfNeeded(Booking $booking): ?string
    {
        return $this->needsSigning($booking) ? $this->url($booking) : null;
    }
}
