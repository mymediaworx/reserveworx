@php use App\Support\Display; @endphp
<div class="page">
    <header class="page-head">
        <div>
            <h1>Bookings</h1>
            <p class="lede">{{ $bookings->total() }} {{ $bookings->total() === 1 ? 'booking' : 'bookings' }} match.</p>
        </div>
        <a class="button primary" href="/staff/bookings/new" wire:navigate>New booking</a>
    </header>

    @if (session('status'))
        <p class="flash ok" role="status">{{ session('status') }}</p>
    @endif

    <form class="filters" wire:submit.prevent>
        <label class="field compact grow">
            <span>Search</span>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Customer name or email">
        </label>
        <label class="field compact">
            <span>When</span>
            <select wire:model.live="when">
                <option value="upcoming">Upcoming</option>
                <option value="past">Past</option>
                <option value="all">All</option>
            </select>
        </label>
        <label class="field compact">
            <span>Status</span>
            <select wire:model.live="status">
                <option value="">Any status</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ Display::status($option->value) }}</option>
                @endforeach
            </select>
        </label>
    </form>

    @if ($bookings->isEmpty())
        <p class="empty">No bookings match these filters.</p>
    @else
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Service</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="num">Party</th>
                        <th scope="col">Status</th>
                        <th scope="col">Payment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bookings as $booking)
                        @php $z = $zones->get($booking->location_id, $zone); $c = $customers->get($booking->customer_id); @endphp
                        <tr>
                            <td><a href="/staff/bookings/{{ $booking->id }}" wire:navigate>{{ Display::dateTime($booking->starts_at, $z) }}</a></td>
                            <td>{{ $booking->service?->name }}</td>
                            <td>{{ $c?->name ?: ($c?->email ?: 'Walk-in') }}</td>
                            <td class="num">{{ $booking->participants }}</td>
                            <td><span class="badge badge-{{ $booking->status->value }}">{{ Display::status($booking->status->value) }}</span></td>
                            <td>{{ Display::status($booking->payment_status->value) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $bookings->links('partials.pager') }}
    @endif
</div>
