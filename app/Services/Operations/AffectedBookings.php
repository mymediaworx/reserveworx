<?php

namespace App\Services\Operations;

use App\Models\Allocation;
use App\Models\Booking;
use Carbon\CarbonImmutable;

final class AffectedBookings
{
    /**
     * Flags future bookings that use this resource in the interval.
     * Does not cancel or reassign them.
     */
    public function flag(?string $assetId, ?string $poolId, CarbonImmutable $startsAt, CarbonImmutable $endsAt, string $reason): int
    {
        $allocationQuery = Allocation::query()
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        if ($assetId !== null) {
            $allocationQuery->where('asset_id', $assetId);
        } else {
            $allocationQuery->where('pool_id', $poolId);
        }

        $bookingIds = $allocationQuery->pluck('booking_id')->unique()->values();
        $flagged = 0;

        foreach ($bookingIds as $bookingId) {
            $booking = Booking::query()->find($bookingId);

            if (! $booking instanceof Booking) {
                continue;
            }

            if (! in_array($booking->status->value, ['held', 'confirmed'], true)) {
                continue;
            }

            if (CarbonImmutable::instance($booking->starts_at)->utc()->lessThanOrEqualTo(CarbonImmutable::now()->utc())) {
                continue;
            }

            $booking->attention_reason = mb_substr($reason, 0, 160);
            $booking->save();
            $flagged++;
        }

        return $flagged;
    }
}
