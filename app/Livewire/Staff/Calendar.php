<?php

namespace App\Livewire\Staff;

use App\Livewire\Concerns\WithinTenant;
use App\Livewire\Staff\Concerns\LoadsBookingRows;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Calendar')]
class Calendar extends Component
{
    use LoadsBookingRows, WithinTenant;

    #[Url]
    public string $date = '';

    #[Url]
    public string $view = 'week';

    #[Url]
    public string $location = '';

    #[Url]
    public string $service = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);

        if (! $this->validDate($this->date)) {
            $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
        }

        if (! in_array($this->view, ['day', 'week'], true)) {
            $this->view = 'week';
        }
    }

    public function shift(int $direction): void
    {
        $step = $this->view === 'day' ? 1 : 7;
        $this->date = $this->day()->addDays($direction > 0 ? $step : -$step)->toDateString();
    }

    public function today(): void
    {
        $this->date = CarbonImmutable::now($this->defaultTimezone())->toDateString();
    }

    public function render(): View
    {
        $zone = $this->defaultTimezone();
        $first = $this->view === 'day' ? $this->day() : $this->day()->startOfWeek(CarbonImmutable::MONDAY);
        $days = $this->view === 'day' ? 1 : 7;
        $start = $first->startOfDay()->utc();
        $end = $first->addDays($days)->startOfDay()->utc();
        $location = $this->location !== '' ? $this->location : null;
        $service = $this->service !== '' ? $this->service : null;

        if ($location !== null && ! Location::query()->whereKey($location)->exists()) {
            abort(404);
        }

        $bookings = Booking::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($location, fn ($query, $id) => $query->where('location_id', $id))
            ->when($service, fn ($query, $id) => $query->where('service_id', $id))
            ->orderBy('starts_at')
            ->get();

        $departures = Departure::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->when($location, fn ($query, $id) => $query->where('location_id', $id))
            ->when($service, fn ($query, $id) => $query->where('service_id', $id))
            ->orderBy('starts_at')
            ->get();

        $columns = [];

        for ($i = 0; $i < $days; $i++) {
            $date = $first->addDays($i);
            $columns[] = [
                'date' => $date,
                'bookings' => $bookings->filter(fn (Booking $b) => CarbonImmutable::instance($b->starts_at)->timezone($zone)->isSameDay($date)),
                'departures' => $departures->filter(fn (Departure $d) => CarbonImmutable::instance($d->starts_at)->timezone($zone)->isSameDay($date)),
            ];
        }

        return view('livewire.staff.calendar', [
            'zone' => $zone,
            'columns' => $columns,
            'first' => $first,
            'last' => $first->addDays($days - 1),
            'customers' => $this->customersFor($bookings),
            'zones' => $this->zones(),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'services' => Service::query()->orderBy('name')->get(['id', 'name']),
            'todayDate' => CarbonImmutable::now($zone)->toDateString(),
        ]);
    }

    private function day(): CarbonImmutable
    {
        return $this->validDate($this->date)
            ? CarbonImmutable::createFromFormat('Y-m-d', $this->date, $this->defaultTimezone())->startOfDay()
            : CarbonImmutable::now($this->defaultTimezone())->startOfDay();
    }

    private function validDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
