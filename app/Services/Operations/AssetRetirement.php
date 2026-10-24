<?php

namespace App\Services\Operations;

use App\Enums\BookingStatus;
use App\Enums\ResourceStatus;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retires one asset, flags the bookings that still use it, and offers
 * reassignment onto a replacement without moving those bookings.
 */
final class AssetRetirement
{
    public function __construct(private readonly AffectedBookings $affected) {}

    /**
     * @return array{asset: Asset, flagged_bookings: int, reassignment_offers: list<array{booking_id: string, from_asset_id: string, to_asset_id: string}>}
     */
    public function retire(Asset $asset, ?string $replacementId): array
    {
        return DB::transaction(function () use ($asset, $replacementId): array {
            $locked = Asset::query()->lockForUpdate()->find($asset->id);

            if (! $locked instanceof Asset) {
                throw ValidationException::withMessages([
                    'asset' => 'That asset is not in this workspace.',
                ]);
            }

            if ($locked->status === ResourceStatus::Retired) {
                throw ValidationException::withMessages([
                    'status' => 'This asset is already retired.',
                ]);
            }

            $replacement = $this->replacement($locked, $replacementId);
            $previous = $locked->status;
            $locked->status = ResourceStatus::Retired;
            $locked->replacement_asset_id = $replacement?->id;
            $locked->save();

            return [
                'asset' => $locked,
                'flagged_bookings' => $this->affected->flagAssetStatus($locked, $previous),
                'reassignment_offers' => $replacement instanceof Asset ? $this->offers($locked, $replacement) : [],
            ];
        });
    }

    private function replacement(Asset $asset, ?string $replacementId): ?Asset
    {
        if ($replacementId === null || $replacementId === '') {
            return null;
        }

        if ($replacementId === $asset->id) {
            throw ValidationException::withMessages([
                'replacement_asset_id' => 'Choose a different asset.',
            ]);
        }

        $replacement = Asset::query()->find($replacementId);

        if (! $replacement instanceof Asset
            || $asset->resource_category_id === null
            || $replacement->resource_category_id !== $asset->resource_category_id
            || $replacement->location_id !== $asset->location_id
            || $replacement->status !== ResourceStatus::Active) {
            throw ValidationException::withMessages([
                'replacement_asset_id' => 'Choose another active asset in the same category and location.',
            ]);
        }

        return $replacement;
    }

    /**
     * Future confirmed bookings only. Reassignment itself stays on the booking route.
     *
     * @return list<array{booking_id: string, from_asset_id: string, to_asset_id: string}>
     */
    private function offers(Asset $asset, Asset $replacement): array
    {
        $startsAt = CarbonImmutable::now()->utc();
        $endsAt = $startsAt->addYear();
        $bookingIds = Allocation::query()
            ->where('asset_id', $asset->id)
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->pluck('booking_id')
            ->unique()
            ->values();
        $offers = [];

        foreach ($bookingIds as $bookingId) {
            if (! is_string($bookingId)) {
                continue;
            }

            $booking = Booking::query()->find($bookingId);

            if (! $booking instanceof Booking || $booking->status !== BookingStatus::Confirmed) {
                continue;
            }

            if (CarbonImmutable::instance($booking->starts_at)->utc()->lessThanOrEqualTo($startsAt)) {
                continue;
            }

            $offers[] = [
                'booking_id' => $booking->id,
                'from_asset_id' => $asset->id,
                'to_asset_id' => $replacement->id,
            ];
        }

        return $offers;
    }
}
