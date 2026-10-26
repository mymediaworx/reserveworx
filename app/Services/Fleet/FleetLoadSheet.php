<?php

namespace App\Services\Fleet;

use App\Enums\ResourceStatus;
use App\Models\FleetItem;
use App\Models\Location;
use App\Tenancy\StaffLocationScope;

/**
 * How many of each fleet item to unload at each location on one calendar day.
 * The counts come from that day's bookings, plus walk-in units still held back.
 */
final class FleetLoadSheet
{
    public function __construct(private readonly FleetCapacity $capacity) {}

    /**
     * @return array{date: string, restricted: bool, items: list<array{id: string, name: string, quantity: int, locations: list<array{id: string, name: string, booked: int, walk_in: int, load: int}>, load: int, on_truck: int|null}>}
     */
    public function forDate(string $date, bool $restricted): array
    {
        $items = FleetItem::query()
            ->where('status', ResourceStatus::Active)
            ->orderBy('name')
            ->get();

        $locations = Location::query()
            ->where('delivered_from_fleet', true)
            ->orderBy('name')
            ->get();

        $rows = [];

        foreach ($items as $item) {
            $stops = [];
            $visible = 0;

            foreach ($locations as $location) {
                $booked = $this->capacity->bookedAt($item->id, $date, $location);
                $load = $this->capacity->loadAt($item->id, $date, $location);
                $visible += $load;
                $stops[] = [
                    'id' => $location->id,
                    'name' => $location->name,
                    'booked' => $booked,
                    'walk_in' => max(0, $load - $booked),
                    'load' => $load,
                ];
            }

            $total = $restricted ? $visible : $this->capacity->loadTotal($item->id, $date);

            $rows[] = [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => $item->quantity,
                'locations' => $stops,
                'load' => $total,
                'on_truck' => $restricted ? null : max(0, $item->quantity - $total),
            ];
        }

        return [
            'date' => $date,
            'restricted' => $restricted,
            'items' => $rows,
        ];
    }

    public function hasFleetLocationsOutsideScope(): bool
    {
        $visible = Location::query()->where('delivered_from_fleet', true)->pluck('id');
        $all = Location::query()
            ->withoutGlobalScope(StaffLocationScope::class)
            ->where('delivered_from_fleet', true)
            ->pluck('id');

        return $all->diff($visible)->isNotEmpty();
    }
}
