@php use App\Support\Display; @endphp
<div class="page bookings-page">
    <style>
        .bookings-page .booking-pick { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: 0.5rem; align-items: start; }
        .bookings-page .bulk-bar { margin-bottom: 1.25rem; }
        .bookings-page .cancel-confirm { margin: 0 0 1.25rem; }
        @media (max-width: 390px) {
            .bookings-page { min-width: 0; }
            .bookings-page .page-head .button,
            .bookings-page .filters,
            .bookings-page .view-save,
            .bookings-page .bulk-bar,
            .bookings-page .cancel-confirm .actions { display: grid; }
            .bookings-page .filters .field,
            .bookings-page .view-save .field,
            .bookings-page .view-save .button,
            .bookings-page .bulk-bar .button,
            .bookings-page .cancel-confirm .button { width: 100%; min-width: 0; }
            .bookings-page .booking-pick,
            .bookings-page .booking-card,
            .bookings-page .booking-card * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Bookings</h1>
            <p class="lede">{{ $bookings->total() }} {{ $bookings->total() === 1 ? 'booking' : 'bookings' }} match.</p>
        </div>
        <a class="button primary" href="/staff/bookings/new" wire:navigate>New booking</a>
    </header>

    @if ($flash)
        <p class="flash ok" role="status">{{ $flash }}</p>
    @endif
    @if ($problem)
        <p class="flash bad" role="alert">{{ $problem }}</p>
    @endif
    @if ($dateProblem)
        <p class="flash bad" role="alert">{{ $dateProblem }}</p>
    @endif
    @include('livewire.staff.partials.undo-toast')

    <form class="filters" wire:submit.prevent>
        <label class="field compact">
            <span>Saved view</span>
            <select wire:model.live="savedView">
                <option value="">No saved view</option>
                @foreach ($views as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
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
                <option value="attention">Needs attention</option>
                <option value="waiting">Waiting on payment</option>
                @foreach ($statuses as $option)
                    <option value="{{ $option->value }}">{{ Display::status($option->value) }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>From</span>
            <input type="date" wire:model.live="from">
        </label>
        <label class="field compact">
            <span>To</span>
            <input type="date" wire:model.live="to">
        </label>
        <label class="field compact">
            <span>Location</span>
            <select wire:model.live="location">
                <option value="">Any location</option>
                @foreach ($locations as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Item</span>
            <select wire:model.live="item">
                <option value="">Any item</option>
                @foreach ($services as $option)
                    <option value="{{ $option->id }}">{{ $option->name }}</option>
                @endforeach
            </select>
        </label>
    </form>

    <form class="view-save filters" wire:submit="saveView">
        <label class="field compact grow">
            <span>Save this view</span>
            <input type="text" wire:model="viewName" maxlength="60" placeholder="Name this filter set">
        </label>
        <button type="submit" class="button">Save view</button>
        @if ($savedView !== '')
            <button type="button" class="button" wire:click="deleteView">Delete view</button>
        @endif
    </form>
    @error('viewName')<p class="error">{{ $message }}</p>@enderror

    <div class="bulk-bar actions">
        <button type="button" class="button small" wire:click="selectPage">Select these</button>
        <button type="button" class="button small" wire:click="clearSelection">Clear</button>
        @if ($canCheckIn)
            <button type="button" class="button" wire:click="checkInSelected">Check in</button>
        @endif
        @if ($canManage)
            <button type="button" class="button" wire:click="returnSelected">Mark returned</button>
            <button type="button" class="button danger" wire:click="askCancel">Cancel</button>
        @endif
        <button type="button" class="button" wire:click="exportSelected">Export CSV</button>
    </div>

    @if ($confirmingCancel)
        <section class="panel cancel-confirm" aria-label="Confirm cancellation">
            <h2>Cancel {{ $selectedCount }} {{ $selectedCount === 1 ? 'booking' : 'bookings' }}?</h2>
            <p>This releases the {{ $industry->units() }}. A card payment is refunded in full, including the booking fee. An offline payment is not refunded.</p>
            <div class="actions">
                <button type="button" class="button danger" wire:click="cancelSelected">Cancel these bookings</button>
                <button type="button" class="button" wire:click="dismissCancel">Keep them</button>
            </div>
        </section>
    @endif

    @if ($bookings->isEmpty())
        <p class="empty">No bookings match these filters.</p>
    @else
        <ul class="booking-cards">
            @foreach ($bookings as $booking)
                @php $z = $zones->get($booking->location_id, $zone); $c = $customers->get($booking->customer_id); $who = $c?->name ?: ($c?->email ?: 'Walk-in'); @endphp
                <li class="booking-pick">
                    <label class="check">
                        <input type="checkbox" wire:click="toggle('{{ $booking->id }}')" @checked(in_array($booking->id, $selected, true)) aria-label="Select {{ $who }}">
                    </label>
                    <a class="booking-card" href="/staff/bookings/{{ $booking->id }}" wire:navigate>
                        <span class="when" data-when>{{ Display::dateTimeWithZone($booking->starts_at, $z) }}</span>
                        <strong>{{ $booking->service?->name }}</strong>
                        <span class="badge badge-{{ $booking->status->value }}">{{ Display::status($booking->status->value) }}</span>
                        @if ($booking->attention_reason && in_array($booking->status->value, ['held', 'pending_payment', 'confirmed'], true))
                            <span class="badge badge-payment" title="{{ $booking->attention_reason }}">Needs attention</span>
                        @endif
                        <span class="muted small">{{ $who }}, {{ $booking->participants }} {{ $booking->participants === 1 ? 'person' : 'people' }}</span>
                        <span class="muted small">{{ $booking->location?->name }}</span>
                        <span class="muted small">{{ Display::status($booking->payment_status->value) }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
        <div class="table-wrap responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th scope="col"><span class="sr-only">Select</span></th>
                        <th scope="col">When</th>
                        <th scope="col">Item</th>
                        <th scope="col">Location</th>
                        <th scope="col">Customer</th>
                        <th scope="col" class="num">Party</th>
                        <th scope="col">Status</th>
                        <th scope="col">Payment</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bookings as $booking)
                        @php $z = $zones->get($booking->location_id, $zone); $c = $customers->get($booking->customer_id); $who = $c?->name ?: ($c?->email ?: 'Walk-in'); @endphp
                        <tr>
                            <td>
                                <label class="check">
                                    <input type="checkbox" wire:click="toggle('{{ $booking->id }}')" @checked(in_array($booking->id, $selected, true)) aria-label="Select {{ $who }}">
                                </label>
                            </td>
                            <td><a href="/staff/bookings/{{ $booking->id }}" data-when wire:navigate>{{ Display::dateTimeWithZone($booking->starts_at, $z) }}</a></td>
                            <td>{{ $booking->service?->name }}</td>
                            <td>{{ $booking->location?->name }}</td>
                            <td>{{ $who }}</td>
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
