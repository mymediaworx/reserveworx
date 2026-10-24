<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Livewire\Staff\Concerns\LoadsBookingRows;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Service;
use App\Models\TenantSetting;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Home')]
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
        $active = [BookingStatus::Confirmed->value, BookingStatus::Held->value, BookingStatus::PendingPayment->value];

        $today = Booking::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->whereNotIn('status', [BookingStatus::Cancelled->value])
            ->orderBy('starts_at')
            ->get();

        $departures = Departure::query()
            ->with('service')
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->orderBy('starts_at')
            ->get();

        $setting = TenantSetting::query()->first();
        $currency = $setting instanceof TenantSetting ? $setting->currency : 'USD';
        $user = auth()->user();
        $firstName = trim((string) strtok((string) ($user->name ?? ''), ' '));

        return view('livewire.staff.dashboard', [
            'zone' => $zone,
            'now' => $now,
            'firstName' => $firstName,
            'today' => $today,
            'departures' => $departures,
            'todayCount' => $today->count(),
            'waitingCount' => Booking::query()->where('status', BookingStatus::Held->value)->count(),
            'upcomingCount' => Booking::query()
                ->where('starts_at', '>', $end)
                ->where('starts_at', '<', $now->addDays(7)->endOfDay()->utc())
                ->whereIn('status', $active)
                ->count(),
            'paidCents' => (int) Payment::query()
                ->where('status', 'paid')
                ->where('created_at', '>=', $now->subDays(30)->utc())
                ->sum('amount_cents'),
            'currency' => $currency,
            'customers' => $this->customersFor($today),
            'zones' => $this->zones(),
            'isEmptyWorkspace' => ! Location::query()->exists() || ! Service::query()->exists(),
            'canManageSetup' => $this->allows('inventory.manage'),
        ]);
    }
}
