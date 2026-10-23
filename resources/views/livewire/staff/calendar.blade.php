@php use App\Support\Display; @endphp
<div class="page">
    <header class="page-head">
        <div>
            <h1>Calendar</h1>
            <p class="lede">
                @if ($view === 'day')
                    {{ $first->format('l, F j') }}
                @else
                    {{ $first->format('M j') }} – {{ $last->format('M j, Y') }}
                @endif
            </p>
        </div>
        <div class="toolbar">
            <button type="button" class="button" wire:click="shift(-1)" aria-label="Previous {{ $view }}">Previous</button>
            <button type="button" class="button" wire:click="today">Today</button>
            <button type="button" class="button" wire:click="shift(1)" aria-label="Next {{ $view }}">Next</button>
        </div>
    </header>

    <form class="filters" wire:submit.prevent>
        <label class="field compact">
            <span>View</span>
            <select wire:model.live="view">
                <option value="week">Week</option>
                <option value="day">Day</option>
            </select>
        </label>
        <label class="field compact">
            <span>Date</span>
            <input type="date" wire:model.live="date">
        </label>
        <label class="field compact">
            <span>Location</span>
            <select wire:model.live="location">
                <option value="">All locations</option>
                @foreach ($locations as $loc)
                    <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Service</span>
            <select wire:model.live="service">
                <option value="">All services</option>
                @foreach ($services as $svc)
                    <option value="{{ $svc->id }}">{{ $svc->name }}</option>
                @endforeach
            </select>
        </label>
    </form>

    <div @class(['calendar', 'is-week' => $view === 'week'])>
        @foreach ($columns as $column)
            <section @class(['calendar-day', 'is-today' => $column['date']->toDateString() === $todayDate])>
                <h2>
                    <span class="weekday">{{ $column['date']->format('D') }}</span>
                    <span class="daynum">{{ $column['date']->format('j') }}</span>
                </h2>
                @if ($column['bookings']->isEmpty() && $column['departures']->isEmpty())
                    <p class="empty small">Open</p>
                @else
                    <ol class="time-rail compact">
                        @foreach ($column['bookings'] as $booking)
                            <x-booking-row :booking="$booking" :customer="$customers->get($booking->customer_id)" :zone="$zones->get($booking->location_id, $zone)" />
                        @endforeach
                    </ol>
                    @foreach ($column['departures'] as $departure)
                        <p class="departure-chip">
                            <strong>{{ Display::time($departure->starts_at, $zones->get($departure->location_id, $zone)) }}</strong>
                            {{ $departure->service?->name }}
                            <span class="muted">{{ $departure->headcount() }}{{ $departure->seats ? '/'.$departure->seats : '' }}</span>
                        </p>
                    @endforeach
                @endif
            </section>
        @endforeach
    </div>
</div>
