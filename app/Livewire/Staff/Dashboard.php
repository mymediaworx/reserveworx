<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Livewire\Staff\Concerns\LoadsBookingRows;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\PlatformFee;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Today')]
class Dashboard extends Component
{
    use LoadsBookingRows, WithinTenant;

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
    }

    public function render(): View
    {
        $zone = $this->defaultTimezone();
        $now = CarbonImmutable::now($zone);
        $start = $now->startOfDay()->utc();
        $end = $now->endOfDay()->utc();

        $today = Booking::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->whereNotIn('status', [BookingStatus::Cancelled->value])
            ->orderBy('starts_at')
            ->get();

        $held = Booking::query()
            ->with('service')
            ->where('status', BookingStatus::Held->value)
            ->orderBy('starts_at')
            ->limit(10)
            ->get();

        $week = Booking::query()
            ->where('starts_at', '>=', $start)
            ->where('starts_at', '<', $now->addDays(7)->endOfDay()->utc())
            ->whereIn('status', [BookingStatus::Confirmed->value, BookingStatus::Held->value, BookingStatus::PendingPayment->value])
            ->count();

        $departures = Departure::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();

        $fees = PlatformFee::query()->get();

        return view('livewire.staff.dashboard', [
            'zone' => $zone,
            'now' => $now,
            'today' => $today,
            'held' => $held,
            'departures' => $departures,
            'weekCount' => $week,
            'customers' => $this->customersFor($today->concat($held)),
            'zones' => $this->zones(),
            'feesNet' => (int) $fees->sum('amount_cents') - (int) $fees->sum('refunded_cents'),
            'isEmptyWorkspace' => ! Location::query()->exists() || ! Service::query()->exists(),
        ]);
    }
}
