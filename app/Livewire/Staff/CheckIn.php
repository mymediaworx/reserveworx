<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Services\CheckIn\CheckInLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Check in')]
class CheckIn extends Component
{
    use WithinTenant;

    #[Url]
    public string $location = '';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('checkInAny', Booking::class);
    }

    public function render(CheckInLink $links): View
    {
        $locations = Location::query()->orderBy('name')->orderBy('id')->get();
        $selected = $this->location === '' ? null : $locations->firstWhere('id', $this->location);

        if ($this->location !== '' && ! $selected instanceof Location) {
            abort(404);
        }

        if (! $selected instanceof Location) {
            $selected = $locations->first();
        }

        $bookings = $selected instanceof Location ? $this->arrivals($selected) : new Collection;
        $customerIds = $bookings->pluck('customer_id')->filter()->unique()->values();

        return view('livewire.staff.check-in', [
            'locations' => $locations,
            'selected' => $selected instanceof Location ? $selected : null,
            'bookings' => $bookings,
            'customers' => $customerIds->isEmpty()
                ? new Collection
                : Customer::query()->whereIn('id', $customerIds)->get()->keyBy('id'),
            'links' => $bookings->mapWithKeys(fn (Booking $booking): array => [
                $booking->id => $links->url($booking),
            ]),
            'zone' => $selected instanceof Location ? $this->zone($selected) : 'UTC',
        ]);
    }

    /**
     * @return Collection<int, Booking>
     */
    private function arrivals(Location $location): Collection
    {
        $zone = $this->zone($location);
        $start = CarbonImmutable::now($zone)->startOfDay();
        $end = $start->addDay();
        $term = trim($this->search);

        $query = Booking::query()
            ->with('service')
            ->where('location_id', $location->id)
            ->where('status', BookingStatus::Confirmed)
            ->where('starts_at', '>=', $start->utc())
            ->where('starts_at', '<', $end->utc())
            ->orderBy('starts_at')
            ->orderBy('id');

        if ($term !== '') {
            $query->where(function ($inner) use ($term): void {
                $inner->where('id', $term)
                    ->orWhere('id', 'like', $this->likePrefix($term))
                    ->orWhereHas('service', fn ($service) => $service->where('name', 'like', $this->likeContains($term)))
                    ->orWhereHas('customer', function ($customer) use ($term): void {
                        $customer->where('name', 'like', $this->likeContains($term))
                            ->orWhere('email', 'like', $this->likeContains($term));
                    });
            });
        }

        return $query->limit(300)->get();
    }

    private function zone(Location $location): string
    {
        $zone = $location->timezone;

        return in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
    }

    private function likeContains(string $term): string
    {
        return '%'.$this->likeEscape($term).'%';
    }

    private function likePrefix(string $term): string
    {
        return $this->likeEscape($term).'%';
    }

    private function likeEscape(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }
}
