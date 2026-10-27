<?php

namespace App\Services\Fleet;

use App\Enums\BookingStatus;
use App\Models\Allocation;
use App\Models\Booking;
use App\Models\FleetItem;
use App\Models\Hold;
use App\Models\Location;
use App\Models\LocationFleetReserve;
use App\Services\Allocation\AllocationUnavailableException;
use App\Support\DisplayZone;
use App\Tenancy\StaffLocationScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * One workspace count for a fleet item, shared by every location the truck
 * delivers to. A booking takes its units for each local calendar date it
 * covers, so the same unit cannot be booked at two places on that day.
 */
final class FleetCapacity
{
    /**
     * Local calendar dates the half-open interval occupies.
     *
     * @return list<string>
     */
    public function dates(Location $location, CarbonImmutable $startUtc, CarbonImmutable $endUtc): array
    {
        return $this->datesIn($location->timezone, $startUtc, $endUtc);
    }

    public function assertFits(
        FleetItem $item,
        Location $location,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        int $quantity,
        bool $counterSale,
        ?string $ignoreBookingId,
    ): void {
        $rows = $this->commitments($item->id, $ignoreBookingId);
        $reserves = $this->reserves($item->id);

        foreach ($this->dates($location, $startUtc, $endUtc) as $date) {
            $remaining = $this->remaining($item->quantity, $rows, $reserves, $date, $counterSale ? $location->id : null);

            if ($quantity > $remaining) {
                throw new AllocationUnavailableException('That day is fully booked.');
            }
        }
    }

    public function bookedAt(string $fleetItemId, string $date, Location $location): int
    {
        return $this->sum($this->commitments($fleetItemId, null), $date, $location->id, null);
    }

    public function counterSoldAt(string $fleetItemId, string $date, Location $location): int
    {
        return $this->sum($this->commitments($fleetItemId, null), $date, $location->id, true);
    }

    /**
     * Booked units plus walk-in units still held back at this location.
     */
    public function loadAt(string $fleetItemId, string $date, Location $location): int
    {
        $rows = $this->commitments($fleetItemId, null);
        $booked = $this->sum($rows, $date, $location->id, null);

        return $booked + $this->unsoldReserve($rows, $fleetItemId, $date, $location);
    }

    /**
     * Units to deliver across every fleet location, including ones this
     * member cannot open.
     */
    public function loadTotal(string $fleetItemId, string $date): int
    {
        $total = 0;

        $locations = Location::query()
            ->withoutGlobalScope(StaffLocationScope::class)
            ->where('delivered_from_fleet', true)
            ->get();

        foreach ($locations as $location) {
            $total += $this->loadAt($fleetItemId, $date, $location);
        }

        return $total;
    }

    /**
     * @param  list<array{location_id: string, timezone: string, quantity: int, counter_sale: bool, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $rows
     * @param  array<string, int>  $reserves
     */
    private function remaining(int $quantity, array $rows, array $reserves, string $date, ?string $exceptLocationId): int
    {
        $booked = $this->sum($rows, $date, null, null);
        $heldBack = 0;

        foreach ($reserves as $locationId => $reserved) {
            if ($exceptLocationId !== null && $locationId === $exceptLocationId) {
                continue;
            }

            $sold = $this->sum($rows, $date, $locationId, true);
            $heldBack += max(0, $reserved - $sold);
        }

        return max(0, $quantity - $booked - $heldBack);
    }

    /**
     * @param  list<array{location_id: string, timezone: string, quantity: int, counter_sale: bool, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $rows
     */
    private function unsoldReserve(array $rows, string $fleetItemId, string $date, Location $location): int
    {
        if (! $location->delivered_from_fleet || ! $location->walk_ins_enabled) {
            return 0;
        }

        $reserved = (int) LocationFleetReserve::query()
            ->where('location_id', $location->id)
            ->where('fleet_item_id', $fleetItemId)
            ->value('quantity');
        $sold = $this->sum($rows, $date, $location->id, true);

        return max(0, $reserved - $sold);
    }

    /**
     * @param  list<array{location_id: string, timezone: string, quantity: int, counter_sale: bool, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>  $rows
     */
    private function sum(array $rows, string $date, ?string $locationId, ?bool $counterSale): int
    {
        $total = 0;

        foreach ($rows as $row) {
            if ($locationId !== null && $row['location_id'] !== $locationId) {
                continue;
            }

            if ($counterSale !== null && $row['counter_sale'] !== $counterSale) {
                continue;
            }

            if (! in_array($date, $this->datesIn($row['timezone'], $row['starts_at'], $row['ends_at']), true)) {
                continue;
            }

            $total += $row['quantity'];
        }

        return $total;
    }

    /**
     * Holds and allocations for this item, across every location. An expired
     * hold does not count. A cancelled booking does not count.
     *
     * @return list<array{location_id: string, timezone: string, quantity: int, counter_sale: bool, starts_at: CarbonImmutable, ends_at: CarbonImmutable}>
     */
    private function commitments(string $fleetItemId, ?string $ignoreBookingId): array
    {
        $allocations = Allocation::query()
            ->where('fleet_item_id', $fleetItemId)
            ->when($ignoreBookingId !== null && $ignoreBookingId !== '', fn ($query) => $query->where('booking_id', '!=', $ignoreBookingId))
            ->get(['booking_id', 'quantity', 'starts_at', 'ends_at']);

        $holds = Hold::query()
            ->where('fleet_item_id', $fleetItemId)
            ->where('expires_at', '>', now())
            ->when($ignoreBookingId !== null && $ignoreBookingId !== '', fn ($query) => $query->where('booking_id', '!=', $ignoreBookingId))
            ->get(['booking_id', 'quantity', 'starts_at', 'ends_at']);

        $bookingIds = $allocations->pluck('booking_id')
            ->merge($holds->pluck('booking_id'))
            ->unique()
            ->values();

        $bookings = Booking::query()
            ->withoutGlobalScope(StaffLocationScope::class)
            ->whereIn('id', $bookingIds)
            ->where('status', '!=', BookingStatus::Cancelled->value)
            ->get(['id', 'location_id', 'counter_sale'])
            ->keyBy('id');

        $locations = Location::query()
            ->withoutGlobalScope(StaffLocationScope::class)
            ->whereIn('id', $bookings->pluck('location_id')->unique()->values())
            ->get(['id', 'timezone'])
            ->keyBy('id');

        $rows = [];
        $seen = [];

        foreach ($allocations as $row) {
            $commitment = $this->commitment($bookings, $locations, $row->booking_id, (int) $row->quantity, $row->starts_at, $row->ends_at);

            if ($commitment === null) {
                continue;
            }

            $seen[$row->booking_id] = true;
            $rows[] = $commitment;
        }

        foreach ($holds as $row) {
            if (isset($seen[$row->booking_id])) {
                continue;
            }

            $commitment = $this->commitment($bookings, $locations, $row->booking_id, (int) $row->quantity, $row->starts_at, $row->ends_at);

            if ($commitment === null) {
                continue;
            }

            $rows[] = $commitment;
        }

        return $rows;
    }

    /**
     * @param  Collection<string, Booking>  $bookings
     * @param  Collection<string, Location>  $locations
     * @return array{location_id: string, timezone: string, quantity: int, counter_sale: bool, starts_at: CarbonImmutable, ends_at: CarbonImmutable}|null
     */
    private function commitment(Collection $bookings, Collection $locations, ?string $bookingId, int $quantity, mixed $startsAt, mixed $endsAt): ?array
    {
        if (! is_string($bookingId) || $bookingId === '') {
            return null;
        }

        $booking = $bookings->get($bookingId);

        if (! $booking instanceof Booking) {
            return null;
        }

        $location = $locations->get($booking->location_id);

        if (! $location instanceof Location) {
            return null;
        }

        return [
            'location_id' => $location->id,
            'timezone' => DisplayZone::resolve($location->timezone),
            'quantity' => $quantity,
            'counter_sale' => (bool) $booking->counter_sale,
            'starts_at' => CarbonImmutable::parse($startsAt)->utc(),
            'ends_at' => CarbonImmutable::parse($endsAt)->utc(),
        ];
    }

    /**
     * Walk-in holds that are switched on. Locations that are not delivered
     * from the fleet do not keep a hold-back.
     *
     * @return array<string, int>
     */
    private function reserves(string $fleetItemId): array
    {
        $rows = LocationFleetReserve::query()
            ->where('fleet_item_id', $fleetItemId)
            ->where('quantity', '>', 0)
            ->get(['location_id', 'quantity']);

        $open = Location::query()
            ->withoutGlobalScope(StaffLocationScope::class)
            ->whereIn('id', $rows->pluck('location_id'))
            ->where('delivered_from_fleet', true)
            ->where('walk_ins_enabled', true)
            ->pluck('id')
            ->all();

        $reserves = [];

        foreach ($rows as $row) {
            if (! in_array($row->location_id, $open, true)) {
                continue;
            }

            $reserves[$row->location_id] = (int) $row->quantity;
        }

        return $reserves;
    }

    /**
     * @return list<string>
     */
    private function datesIn(string $timezone, CarbonImmutable $startUtc, CarbonImmutable $endUtc): array
    {
        $start = $startUtc->utc()->timezone($timezone);
        $end = $endUtc->utc()->timezone($timezone);

        if ($end->lessThanOrEqualTo($start)) {
            return [$start->toDateString()];
        }

        $last = $end->subSecond()->startOfDay();
        $cursor = $start->startOfDay();
        $dates = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates === [] ? [$start->toDateString()] : $dates;
    }
}
