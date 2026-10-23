<?php

namespace App\Livewire\Staff;

use App\Enums\BookingStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Livewire\Staff\Concerns\LoadsBookingRows;
use App\Models\Booking;
use App\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.staff')]
#[Title('Bookings')]
class Bookings extends Component
{
    use LoadsBookingRows, WithinTenant, WithPagination;

    #[Url]
    public string $status = '';

    #[Url]
    public string $when = 'upcoming';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Booking::class);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $now = CarbonImmutable::now('UTC');
        $statuses = array_map(fn (BookingStatus $s) => $s->value, BookingStatus::cases());
        $status = in_array($this->status, $statuses, true) ? $this->status : null;
        $term = trim($this->search);

        $query = Booking::query()
            ->with('service')
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($this->when === 'upcoming', fn ($q) => $q->where('ends_at', '>=', $now)->orderBy('starts_at'))
            ->when($this->when === 'past', fn ($q) => $q->where('ends_at', '<', $now)->orderByDesc('starts_at'))
            ->when($this->when === 'all', fn ($q) => $q->orderByDesc('starts_at'));

        if ($term !== '') {
            $customerIds = Customer::query()
                ->where(fn ($q) => $q->where('name', 'like', '%'.$term.'%')->orWhere('email', 'like', '%'.$term.'%'))
                ->pluck('id');
            $query->where(fn ($q) => $q->whereIn('customer_id', $customerIds)->orWhere('id', $term));
        }

        $bookings = $query->paginate(25);

        return view('livewire.staff.bookings', [
            'bookings' => $bookings,
            'customers' => $this->customersFor(collect($bookings->items())),
            'zones' => $this->zones(),
            'zone' => $this->defaultTimezone(),
            'statuses' => BookingStatus::cases(),
        ]);
    }
}
