<?php

namespace App\Services\CheckIn;

use App\Models\Booking;
use App\Services\Tenancy\WorkspaceOrigin;
use Illuminate\Support\Facades\URL;

/**
 * A permanent signed URL on the workspace's real verified domain.
 * Opening it is what checks the booking in. The signature covers the host and the booking id.
 */
final class CheckInLink
{
    public function __construct(private readonly WorkspaceOrigin $origins) {}

    public function url(Booking $booking): ?string
    {
        $origin = $this->origins->baseUrl($booking->tenant_id);

        if ($origin === null) {
            return null;
        }

        return $this->origins->generate(
            $origin,
            fn (): string => URL::signedRoute('staff.check-in.arrive', ['booking' => $booking->id]),
        );
    }
}
