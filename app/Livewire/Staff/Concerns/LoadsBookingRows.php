<?php

namespace App\Livewire\Staff\Concerns;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Support\DisplayZone;
use Illuminate\Support\Collection;

trait LoadsBookingRows
{
    /**
     * Customers keyed by id for a set of bookings, in one query.
     *
     * @param  Collection<int, Booking>  $bookings
     * @return Collection<string, Customer>
     */
    protected function customersFor(Collection $bookings): Collection
    {
        $ids = $bookings->pluck('customer_id')->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        return Customer::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * Location timezones keyed by id.
     *
     * @return Collection<string, string>
     */
    protected function zones(): Collection
    {
        return Location::query()->pluck('timezone', 'id')
            ->map(fn (mixed $zone): string => DisplayZone::resolve($zone));
    }
}
