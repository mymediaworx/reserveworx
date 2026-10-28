@php use App\Support\Display; @endphp
<div class="page check-in-page">
    <style>
        @media (max-width: 390px) {
            .check-in-page { min-width: 0; }
            .check-in-page .booking-card { grid-template-columns: 1fr; min-width: 0; }
            .check-in-page .booking-card,
            .check-in-page .booking-card * { overflow-wrap: anywhere; min-width: 0; }
            .check-in-page .button { width: 100%; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Check in</h1>
            <p class="lede">Today's arrivals. Times are in {{ $zone }}.</p>
        </div>
    </header>

    <form class="filters" wire:submit.prevent>
        @if ($locations->isNotEmpty())
            <label class="field compact">
                <span>Location</span>
                <select wire:model.live="location">
                    @foreach ($locations as $option)
                        <option value="{{ $option->id }}" @selected($selected?->id === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <label class="field compact grow">
            <span>Search</span>
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name, email, service, or booking">
        </label>
    </form>

    @if ($bookings->isEmpty())
        <p class="empty">No arrivals at this location today.</p>
    @else
        <ul class="booking-cards">
            @foreach ($bookings as $booking)
                @php $customer = $customers->get($booking->customer_id); @endphp
                <li class="booking-card">
                    <span class="when" data-when>{{ Display::dateTimeWithZone($booking->starts_at, $zone) }}</span>
                    <strong>{{ $booking->service?->name }}</strong>
                    <span>{{ $customer?->name ?: ($customer?->email ?: 'Walk-in') }}</span>
                    <span class="badge badge-{{ $booking->operational_status->value }}">{{ Display::status($booking->operational_status->value) }}</span>
                    @if (is_string($links->get($booking->id)))
                        <a class="button" href="{{ $links->get($booking->id) }}">Check in</a>
                    @endif
                </li>
            @endforeach
        </ul>
        <div class="table-wrap responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Service</th>
                        <th scope="col">Customer</th>
                        <th scope="col">Arrival</th>
                        <th scope="col"><span class="sr-only">Check in</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bookings as $booking)
                        @php $customer = $customers->get($booking->customer_id); @endphp
                        <tr>
                            <td data-when>{{ Display::dateTimeWithZone($booking->starts_at, $zone) }}</td>
                            <td>{{ $booking->service?->name }}</td>
                            <td>{{ $customer?->name ?: ($customer?->email ?: 'Walk-in') }}</td>
                            <td>{{ Display::status($booking->operational_status->value) }}</td>
                            <td>
                                @if (is_string($links->get($booking->id)))
                                    <a class="button" href="{{ $links->get($booking->id) }}">Check in</a>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
