@php use App\Support\Display; @endphp
<div class="page manifest-page">
    <style>
        .manifest-page { min-width: 0; }
        .manifest-card { grid-template-columns: 1fr; }
        .manifest-facts { display: grid; gap: 0.35rem; margin: 0.5rem 0; }
        .manifest-facts div { display: grid; grid-template-columns: 7.5rem 1fr; gap: 0.25rem 0.75rem; }
        .manifest-facts dt { color: var(--muted); font-size: 0.8rem; font-weight: 500; }
        .manifest-facts dd { margin: 0; overflow-wrap: anywhere; }
        .manifest-location { margin-top: 1.5rem; }
        .manifest-location h2 { margin: 0; }
        .departure-list { list-style: none; margin: 0.75rem 0; padding: 0; display: grid; gap: 0.5rem; }
        .departure-list li { display: flex; flex-wrap: wrap; gap: 0.25rem 0.6rem; align-items: center; min-width: 0; }
        @media (max-width: 390px) {
            .manifest-page { min-width: 0; }
            .manifest-page .manifest-card,
            .manifest-page .manifest-card * { min-width: 0; overflow-wrap: anywhere; }
            .manifest-page .manifest-facts div { grid-template-columns: 1fr; }
            .manifest-page .button,
            .manifest-page .manifest-toolbar .button { width: 100%; }
            .manifest-page .departure-list,
            .manifest-page .departure-list li,
            .manifest-page .departure-list a { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
            .manifest-page .departure-list .badge { white-space: normal; }
        }
        @media print {
            .app-side,
            .app-bar,
            .tab-bar,
            .fab,
            .skip-link,
            .manifest-toolbar,
            .no-print { display: none !important; }
            .app,
            .app-shell,
            .app-main,
            .app-content,
            .manifest-page {
                display: block !important;
                background: #fff !important;
                min-height: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                width: auto !important;
                max-width: none !important;
                overflow: visible !important;
            }
            .manifest-page .booking-cards { display: none !important; }
            .manifest-page .table-wrap.responsive {
                display: block !important;
                overflow: visible !important;
                border: 0;
                box-shadow: none;
            }
            .manifest-page .table th,
            .manifest-page .table td { white-space: normal; }
            .manifest-location { break-after: page; page-break-after: always; }
            .manifest-location:last-child { break-after: auto; page-break-after: auto; }
        }
        @page { size: landscape; margin: 12mm; }
    </style>

    <header class="page-head">
        <div>
            <h1>Dispatch manifest</h1>
            <p class="lede">{{ $headingDate }}</p>
        </div>
    </header>

    <form class="filters manifest-toolbar" wire:submit.prevent>
        <label class="field compact">
            <span>Date</span>
            <input type="date" wire:model.live="date" required>
        </label>
        @if ($locations->isNotEmpty())
            <label class="field compact">
                <span>Location</span>
                <select wire:model.live="location">
                    <option value="">All locations</option>
                    @foreach ($locations as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <button type="button" class="button" wire:click="shift(-1)">Previous day</button>
        <button type="button" class="button" wire:click="today">Today</button>
        <button type="button" class="button" wire:click="shift(1)">Next day</button>
        <button type="button" class="button" onclick="window.print()">Print</button>
        <a class="button" href="/staff/manifest.csv?date={{ urlencode($date) }}@if ($location !== '')&amp;location={{ urlencode($location) }}@endif">Download CSV</a>
    </form>

    @if ($flash)
        <p class="flash ok no-print" role="status">{{ $flash }}</p>
    @endif

    @if ($editing !== '')
        <section class="panel manifest-note no-print" id="manifest-note">
            <h2>Staff note</h2>
            <form wire:submit="saveNote">
                <label class="field">
                    <span>Note</span>
                    <textarea wire:model="note" maxlength="1000" rows="3"></textarea>
                </label>
                @error('note')
                    <p role="alert">{{ $message }}</p>
                @enderror
                @if ($canManage)
                    <button type="submit" class="button">Save note</button>
                @endif
                <button type="button" class="button" wire:click="closeNote">Cancel</button>
            </form>
        </section>
    @endif

    @if ($locations->isEmpty())
        <p class="empty">Add a location to build the manifest.</p>
    @elseif ($sections === [])
        <p class="empty">No bookings for this day.</p>
    @else
        <div class="manifest-sheet">
            @foreach ($sections as $section)
                <section class="manifest-location" data-location="{{ $section['id'] }}">
                    <h2>{{ $section['name'] }}</h2>
                    <p class="lede">{{ $section['timezone'] }}</p>
                    @if (($section['departures'] ?? []) !== [])
                        <h3>Departures</h3>
                        <ul class="departure-list">
                            @foreach ($section['departures'] as $row)
                                <li data-departure="{{ $row['departure']->id }}" @if ($row['belowMinimum']) data-below-minimum="{{ $row['departure']->id }}" @endif>
                                    <strong>{{ Display::time($row['departure']->starts_at, $row['timezone']) }}</strong>
                                    <span>{{ $row['departure']->service?->name ?? 'Tour' }}</span>
                                    <span class="muted">
                                        @if ($row['belowMinimum'] && is_int($row['minimum']))
                                            {{ $row['participants'] }} of {{ $row['minimum'] }}
                                        @else
                                            {{ $row['participants'] }}{{ $row['departure']->seats ? '/'.$row['departure']->seats : '' }}
                                        @endif
                                    </span>
                                    @if ($row['belowMinimum'])
                                        <span class="badge badge-below-minimum">Below minimum</span>
                                    @endif
                                    <a href="/staff/calendar?view=day&amp;date={{ $date }}&amp;location={{ $section['id'] }}" wire:navigate>Calendar</a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($section['bookings'] === [])
                        <p class="empty">No bookings at this location on this day.</p>
                    @else
                        <ul class="booking-cards">
                            @foreach ($section['bookings'] as $booking)
                                <li class="booking-card manifest-card" data-booking="{{ $booking->id }}" data-local-start="{{ $booking->startsAt->timezone($booking->timezone)->format('Y-m-d\TH:i') }}" data-return-due="{{ $booking->returnDue->timezone($booking->timezone)->format('Y-m-d\TH:i') }}" data-balance="{{ $booking->balanceCents === null ? 'none' : $booking->balanceCents }}" data-waiver="{{ $booking->waiverSummary() }}">
                                    <span class="when">{{ Display::time($booking->startsAt, $booking->timezone) }} · back {{ $booking->startsAt->timezone($booking->timezone)->isSameDay($booking->returnDue->timezone($booking->timezone)) ? Display::time($booking->returnDue, $booking->timezone) : Display::dateTime($booking->returnDue, $booking->timezone) }}</span>
                                    <strong>{{ $booking->service }}</strong>
                                    <span class="badge">{{ $booking->checkIn }}</span>
                                    @include('livewire.staff.partials.manifest-facts', ['booking' => $booking, 'canManage' => $canManage])
                                </li>
                            @endforeach
                        </ul>
                        <div class="table-wrap responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th scope="col">When</th>
                                        <th scope="col">Return due</th>
                                        <th scope="col">Resource</th>
                                        <th scope="col">Guide</th>
                                        <th scope="col">Customer</th>
                                        <th scope="col">Party</th>
                                        <th scope="col">Waivers</th>
                                        <th scope="col">Add-ons</th>
                                        <th scope="col">Balance</th>
                                        <th scope="col">Check-in</th>
                                        <th scope="col">Staff notes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($section['bookings'] as $booking)
                                        <tr data-booking="{{ $booking->id }}" data-local-start="{{ $booking->startsAt->timezone($booking->timezone)->format('Y-m-d\TH:i') }}" data-return-due="{{ $booking->returnDue->timezone($booking->timezone)->format('Y-m-d\TH:i') }}" data-balance="{{ $booking->balanceCents === null ? 'none' : $booking->balanceCents }}" data-waiver="{{ $booking->waiverSummary() }}">
                                            <td>{{ Display::time($booking->startsAt, $booking->timezone) }}</td>
                                            <td>{{ $booking->startsAt->timezone($booking->timezone)->isSameDay($booking->returnDue->timezone($booking->timezone)) ? Display::time($booking->returnDue, $booking->timezone) : Display::dateTime($booking->returnDue, $booking->timezone) }}</td>
                                            <td>{{ $booking->resource }}</td>
                                            <td>{{ $booking->guideName !== '' ? $booking->guideName : 'No guide' }}</td>
                                            <td>
                                                {{ $booking->customerName }}
                                                @if ($booking->customerPhone !== '')
                                                    <br><a href="tel:{{ preg_replace('/[^0-9+]/', '', $booking->customerPhone) }}">{{ $booking->customerPhone }}</a>
                                                @endif
                                            </td>
                                            <td>
                                                {{ $booking->partySize }}
                                                @if ($booking->participantSummary() !== '')
                                                    <br>{{ $booking->participantSummary() }}
                                                @endif
                                            </td>
                                            <td>{{ $booking->waiverSummary() }}</td>
                                            <td>{{ $booking->addOns !== '' ? $booking->addOns : '—' }}</td>
                                            <td>
                                                @if ($booking->balanceCents === null)
                                                    No price yet
                                                @else
                                                    {{ Display::money($booking->balanceCents, $booking->currency) }}
                                                @endif
                                            </td>
                                            <td>{{ $booking->checkIn }}</td>
                                            <td>
                                                {{ $booking->staffNotes ?? '—' }}
                                                @if ($canManage)
                                                    <br><button type="button" class="link-button no-print" wire:click="editNote('{{ $booking->id }}')">Edit note</button>
                                                @endif
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </section>
            @endforeach
        </div>
    @endif
</div>
