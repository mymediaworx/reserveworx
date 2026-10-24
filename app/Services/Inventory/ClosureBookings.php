<?php

namespace App\Services\Inventory;

use App\Enums\BookingStatus;
use App\Enums\ResourceType;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Pool;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Confirmed bookings whose customer interval overlaps a closure.
 * Holds, cancellations, and no-shows are left out. Nothing here cancels or refunds.
 */
final class ClosureBookings
{
    public function __construct(private readonly ClosureCalendar $calendar) {}

    /**
     * @return Collection<int, Booking>
     */
    public function overlapping(LocationClosure $closure): Collection
    {
        $location = $closure->relationLoaded('location') ? $closure->location : $closure->location()->first();

        if (! $location instanceof Location) {
            return collect();
        }

        $query = Booking::query()
            ->with('customer')
            ->where('location_id', $location->id)
            ->where('status', BookingStatus::Confirmed)
            ->orderBy('starts_at')
            ->orderBy('id');

        if (! $closure->recurs_yearly) {
            $window = $this->calendar->concrete(
                $location->timezone,
                $closure->all_day,
                $closure->starts_on->toDateString(),
                $closure->ends_on->toDateString(),
                $closure->starts_local,
                $closure->ends_local,
            );

            if ($window[1]->lessThanOrEqualTo($window[0])) {
                return collect();
            }

            $query->where('starts_at', '<', $window[1])->where('ends_at', '>', $window[0]);
        }

        /** @var Collection<int, Booking> $bookings */
        $bookings = $query->get()->filter(function (Booking $booking) use ($closure, $location): bool {
            return $this->calendar->covers(
                $closure,
                $location->timezone,
                CarbonImmutable::instance($booking->starts_at)->utc(),
                CarbonImmutable::instance($booking->ends_at)->utc(),
            );
        })->values();

        return $this->limited($closure, $bookings);
    }

    /**
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<int, Booking>
     */
    private function limited(LocationClosure $closure, Collection $bookings): Collection
    {
        if ($bookings->isEmpty()) {
            return $bookings;
        }

        if (($closure->resource_id === null || $closure->resource_id === '') && ($closure->resource_category_id === null || $closure->resource_category_id === '')) {
            return $bookings;
        }

        $allocations = Allocation::query()->whereIn('booking_id', $bookings->pluck('id'))->get();
        $categories = $this->categories($allocations);

        return $bookings->filter(function (Booking $booking) use ($closure, $allocations, $categories): bool {
            $rows = $allocations->where('booking_id', $booking->id);

            if ($closure->resource_id !== null && $closure->resource_id !== '') {
                $column = $closure->resource_type === ResourceType::Pool->value ? 'pool_id' : 'asset_id';

                return $rows->contains(fn (Allocation $row): bool => $row->{$column} === $closure->resource_id);
            }

            foreach ($rows as $row) {
                $category = $row->asset_id !== null
                    ? ($categories['asset'][$row->asset_id] ?? null)
                    : ($categories['pool'][$row->pool_id] ?? null);

                if ($category === $closure->resource_category_id) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * @param  Collection<int, Allocation>  $allocations
     * @return array{asset: array<string, string|null>, pool: array<string, string|null>}
     */
    private function categories(Collection $allocations): array
    {
        $assetIds = $allocations->pluck('asset_id')->filter()->unique()->values();
        $poolIds = $allocations->pluck('pool_id')->filter()->unique()->values();

        return [
            'asset' => Asset::query()->whereIn('id', $assetIds)->pluck('resource_category_id', 'id')->all(),
            'pool' => Pool::query()->whereIn('id', $poolIds)->pluck('resource_category_id', 'id')->all(),
        ];
    }
}
