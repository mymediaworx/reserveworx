@php use App\Support\Display; use App\Support\DisplayZone; @endphp
<div class="page calendar-page" wire:poll.60s="refreshCalendar">
    <style>
        @media (max-width: 390px) {
            .calendar-page { min-width: 0; max-width: 100%; }
            .calendar-page .page-head,
            .calendar-page .toolbar,
            .calendar-page .filters { display: grid; min-width: 0; }
            .calendar-page .toolbar .button,
            .calendar-page .filters .field,
            .calendar-page .filters .field select,
            .calendar-page .filters .field input { width: 100%; min-width: 0; max-width: 100%; }
            .calendar-page .calendar,
            .calendar-page .calendar-week,
            .calendar-page .calendar-weekdays,
            .calendar-page .calendar-day,
            .calendar-page .month-event,
            .calendar-page .departure-chip,
            .calendar-page .agenda-day { min-width: 0; max-width: 100%; }
            .calendar-page .departure-chip,
            .calendar-page .month-event,
            .calendar-page .agenda-day,
            .calendar-page .rail-time,
            .calendar-page .rail-meta { overflow-wrap: anywhere; }
            .calendar-page .rail-item { min-width: 0; max-width: 100%; }
            .calendar-page .rail-meta { overflow: visible; text-overflow: unset; white-space: normal; }
            .calendar-page .badge { white-space: normal; }
            .calendar-page .lede,
            .calendar-page .zone-note { overflow-wrap: anywhere; }
            .calendar-page .calendar.is-month .calendar-day,
            .calendar-page .calendar.is-month .calendar-day h2,
            .calendar-page .calendar.is-month .calendar-week { overflow: hidden; min-width: 0; }
            .calendar-page .calendar.is-month .weekday { display: none; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Calendar</h1>
            <p class="lede">
                @if ($view === 'day')
                    {{ $first->format('l, F j') }}
                @elseif ($view === 'month')
                    {{ $first->format('F Y') }}
                @else
                    {{ $first->format('M j') }} – {{ $last->format('M j, Y') }}
                @endif
                <span class="zone-note" data-calendar-zone="{{ $zone }}">
                    @if ($location !== '')
                        Days follow {{ $zone }}.
                    @else
                        Each visit stays on its location's date.
                    @endif
                </span>
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
                <option value="day">Day</option>
                <option value="week">Week</option>
                <option value="month">Month</option>
                <option value="agenda">Agenda</option>
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
        <label class="field compact">
            <span>Resource</span>
            <select wire:model.live="resource">
                <option value="">All resources</option>
                @if ($assets->isNotEmpty())
                    <optgroup label="Items">
                        @foreach ($assets as $asset)
                            <option value="asset:{{ $asset->id }}">{{ $asset->name }}</option>
                        @endforeach
                    </optgroup>
                @endif
                @if ($pools->isNotEmpty())
                    <optgroup label="Pools">
                        @foreach ($pools as $pool)
                            <option value="pool:{{ $pool->id }}">{{ $pool->name }}</option>
                        @endforeach
                    </optgroup>
                @endif
            </select>
        </label>
        <label class="field compact">
            <span>Staff member</span>
            <select wire:model.live="staff">
                <option value="">All staff</option>
                @foreach ($staffMembers as $member)
                    <option value="{{ $member->id }}">{{ $member->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Booking status</span>
            <select wire:model.live="status">
                <option value="">Any booking status</option>
                @foreach ($bookingStatuses as $value)
                    <option value="{{ $value }}">{{ Display::status($value) }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Payment status</span>
            <select wire:model.live="payment">
                <option value="">Any payment status</option>
                @foreach ($paymentStatuses as $value)
                    <option value="{{ $value }}">{{ Display::status($value) }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Operational status</span>
            <select wire:model.live="operational">
                <option value="">Any operational status</option>
                @foreach ($operationalStatuses as $value)
                    <option value="{{ $value }}">{{ Display::status($value) }}</option>
                @endforeach
            </select>
        </label>
    </form>

    @if ($view === 'month')
        <div class="calendar is-month">
            <div class="calendar-weekdays" aria-hidden="true">
                @foreach ($weekdayLabels as $label)
                    <span>{{ $label }}</span>
                @endforeach
            </div>
            @foreach ($weeks as $week)
                <div class="calendar-week">
                    @foreach ($week as $day)
                        @php
                            $flagged = array_values(array_filter($day['departures'], fn (array $row): bool => $row['belowMinimum']));
                            $flaggedIds = array_map(fn (array $row): string => $row['departure']->id, $flagged);
                            $rest = [];
                            foreach ($day['bookings'] as $booking) {
                                $rest[] = ['kind' => 'booking', 'booking' => $booking, 'at' => $booking->starts_at];
                            }
                            foreach ($day['departures'] as $row) {
                                if (! in_array($row['departure']->id, $flaggedIds, true)) {
                                    $rest[] = ['kind' => 'departure', 'row' => $row, 'at' => $row['departure']->starts_at];
                                }
                            }
                            usort($rest, fn (array $left, array $right): int => $left['at'] <=> $right['at']);
                            $room = max(0, 4 - count($flagged));
                            $shown = array_slice($rest, 0, $room);
                            $extra = count($rest) - count($shown);
                            $dayKey = $day['date']->toDateString();
                            $dayHref = '/staff/calendar?view=day&date='.$dayKey.$keptQuery;
                            $dayClass = 'calendar-day'.($dayKey === $todayDate ? ' is-today' : '').($day['inMonth'] ? '' : ' is-outside');
                        @endphp
                        <section data-date="{{ $dayKey }}" class="{{ $dayClass }}">
                            <h2>
                                <span class="weekday">{{ $day['date']->format('D') }}</span>
                                <a class="daynum" href="{{ $dayHref }}" wire:navigate>{{ $day['date']->format('j') }}</a>
                            </h2>
                            @foreach ($flagged as $row)
                                <a class="month-event is-below" href="/staff/manifest?date={{ $day['date']->toDateString() }}&amp;location={{ $row['departure']->location_id }}" wire:navigate data-departure="{{ $row['departure']->id }}" data-below-minimum="{{ $row['departure']->id }}" title="{{ $row['departure']->service?->name }} is below its minimum, {{ $row['participants'] }} of {{ $row['minimum'] }}. {{ Display::rangeWithZone($row['departure']->starts_at, $row['departure']->ends_at, DisplayZone::forLocation($row['departure']->location)) }}">
                                    <span class="sr-only">Below minimum. </span>{{ $row['departure']->service?->name ?? 'Tour' }}
                                </a>
                            @endforeach
                            @foreach ($shown as $item)
                                @if ($item['kind'] === 'booking')
                                    <a class="month-event" href="/staff/bookings/{{ $item['booking']->id }}" wire:navigate data-booking="{{ $item['booking']->id }}" title="{{ Display::rangeWithZone($item['booking']->starts_at, $item['booking']->ends_at, DisplayZone::forLocation($item['booking']->location)) }}">{{ $customers->get($item['booking']->customer_id)?->name ?? ($item['booking']->service?->name ?? 'Booking') }}</a>
                                @else
                                    <a class="month-event" href="/staff/manifest?date={{ $day['date']->toDateString() }}&amp;location={{ $item['row']['departure']->location_id }}" wire:navigate data-departure="{{ $item['row']['departure']->id }}" title="{{ Display::rangeWithZone($item['row']['departure']->starts_at, $item['row']['departure']->ends_at, DisplayZone::forLocation($item['row']['departure']->location)) }}">{{ $item['row']['departure']->service?->name ?? 'Tour' }}</a>
                                @endif
                            @endforeach
                            @if ($extra > 0)
                                <a class="month-more" href="{{ $dayHref }}" wire:navigate>+{{ $extra }} more</a>
                            @endif
                        </section>
                    @endforeach
                </div>
            @endforeach
        </div>
    @elseif ($view === 'agenda')
        @php $agendaDays = array_values(array_filter($days, fn (array $day): bool => $day['bookings']->isNotEmpty() || $day['departures'] !== [])); @endphp
        @if ($agendaDays === [])
            <p class="empty">Nothing booked in this stretch.</p>
        @else
            <div class="calendar is-agenda">
                @foreach ($agendaDays as $day)
                    @php
                        $dayKey = $day['date']->toDateString();
                        $dayClass = 'agenda-day calendar-day'.($dayKey === $todayDate ? ' is-today' : '');
                    @endphp
                    <section data-date="{{ $dayKey }}" class="{{ $dayClass }}">
                        <h2>
                            <span class="weekday">{{ $day['date']->format('l') }}</span>
                            <a class="daynum" href="/staff/calendar?view=day&date={{ $day['date']->toDateString() }}{{ $keptQuery }}" wire:navigate>{{ $day['date']->format('M j') }}</a>
                        </h2>
                        @if ($day['bookings']->isNotEmpty())
                            <ol class="time-rail">
                                @foreach ($day['bookings'] as $booking)
                                    @include('livewire.staff.partials.calendar-visit', ['booking' => $booking, 'customer' => $customers->get($booking->customer_id), 'zone' => $zones->get($booking->location_id, $zone)])
                                @endforeach
                            </ol>
                        @endif
                        @foreach ($day['departures'] as $row)
                            @include('livewire.staff.partials.departure-chip', ['row' => $row, 'zone' => $zones->get($row['departure']->location_id, $zone)])
                        @endforeach
                    </section>
                @endforeach
            </div>
        @endif
    @else
        <div @class(['calendar', 'is-week' => $view === 'week', 'is-day' => $view === 'day'])>
            @foreach ($days as $day)
                @php
                    $dayKey = $day['date']->toDateString();
                    $dayClass = 'calendar-day'.($dayKey === $todayDate ? ' is-today' : '');
                @endphp
                <section data-date="{{ $dayKey }}" class="{{ $dayClass }}">
                    <h2>
                        <span class="weekday">{{ $day['date']->format('D') }}</span>
                        <span class="daynum">{{ $day['date']->format('j') }}</span>
                    </h2>
                    @if ($day['bookings']->isEmpty() && $day['departures'] === [])
                        <p class="empty small">Open</p>
                    @else
                        @if ($day['bookings']->isNotEmpty())
                            <ol class="time-rail compact">
                                @foreach ($day['bookings'] as $booking)
                                    @include('livewire.staff.partials.calendar-visit', ['booking' => $booking, 'customer' => $customers->get($booking->customer_id), 'zone' => $zones->get($booking->location_id, $zone)])
                                @endforeach
                            </ol>
                        @endif
                        @foreach ($day['departures'] as $row)
                            @include('livewire.staff.partials.departure-chip', ['row' => $row, 'zone' => $zones->get($row['departure']->location_id, $zone)])
                        @endforeach
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
