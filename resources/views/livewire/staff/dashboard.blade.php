@php use App\Support\Display; @endphp
<div class="page">
    <header class="page-head">
        <div>
            <h1>{{ $now->format('l, F j') }}</h1>
            <p class="lede">
                {{ $today->count() }} {{ $today->count() === 1 ? 'booking' : 'bookings' }} on the water today,
                {{ $weekCount }} in the next seven days.
            </p>
        </div>
        <a class="button primary" href="/staff/bookings/new" wire:navigate>New booking</a>
    </header>

    @if ($isEmptyWorkspace)
        <section class="notice">
            <h2>Set up your workspace</h2>
            <p>Add a location, the boats and gear you rent, and at least one service with a price. Then bookings can start.</p>
            <p><a class="button" href="/staff/setup/locations" wire:navigate>Add a location</a></p>
        </section>
    @endif

    <div class="split">
        <section class="panel" aria-labelledby="today-heading">
            <h2 id="today-heading">Today’s schedule</h2>
            @if ($today->isEmpty() && $departures->isEmpty())
                <p class="empty">Nothing booked today. New bookings show up here as they come in.</p>
            @else
                <ol class="time-rail">
                    @foreach ($today as $booking)
                        <x-booking-row :booking="$booking" :customer="$customers->get($booking->customer_id)" :zone="$zones->get($booking->location_id, $zone)" />
                    @endforeach
                </ol>
                @if ($departures->isNotEmpty())
                    <h3>Tour departures</h3>
                    <ul class="plain-list">
                        @foreach ($departures as $departure)
                            <li>
                                <strong>{{ Display::time($departure->starts_at, $zones->get($departure->location_id, $zone)) }}</strong>
                                {{ $departure->service?->name }}
                                <span class="muted">{{ $departure->headcount() }}{{ $departure->seats ? ' of '.$departure->seats : '' }} booked</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            @endif
        </section>

        <aside class="stack">
            <section class="panel attention" aria-labelledby="held-heading">
                <h2 id="held-heading">Waiting on payment</h2>
                @if ($held->isEmpty())
                    <p class="empty">No holds right now.</p>
                @else
                    <ul class="plain-list">
                        @foreach ($held as $booking)
                            <li>
                                <a href="/staff/bookings/{{ $booking->id }}" wire:navigate>{{ $booking->service?->name }}</a>
                                <span class="muted">{{ Display::dateTime($booking->starts_at, $zones->get($booking->location_id, $zone)) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
            <section class="panel" aria-labelledby="fees-heading">
                <h2 id="fees-heading">Booking fees</h2>
                <p class="figure">{{ Display::money($feesNet) }}</p>
                <p class="muted">Net booking fees on online payments, after refunds.</p>
            </section>
        </aside>
    </div>
</div>
