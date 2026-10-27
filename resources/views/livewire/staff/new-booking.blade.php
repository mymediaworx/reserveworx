@php use App\Support\Display; @endphp
<div class="page narrow new-booking">
    <style>
        @media (max-width: 390px) {
            .new-booking { min-width: 0; }
            .new-booking .booking-zone,
            .new-booking .slot,
            .new-booking .slot-time,
            .new-booking .field { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
            .new-booking .slot-grid,
            .new-booking .form-row { display: grid; grid-template-columns: minmax(0, 1fr); }
        }
    </style>
    <header class="page-head">
        <h1>New booking</h1>
    </header>

    @if ($services->isEmpty())
        <section class="notice">
            <p>There are no services yet. Add one before taking bookings.</p>
            <p><a class="button" href="/staff/setup/services" wire:navigate>Add a service</a></p>
        </section>
    @else
        <form wire:submit="book" class="stack">
            <fieldset class="panel">
                <legend>What</legend>
                <label class="field">
                    <span>Service</span>
                    <select wire:model.live="serviceId" required>
                        <option value="">Choose a service</option>
                        @foreach ($services as $option)
                            <option value="{{ $option->id }}">{{ $option->name }} ({{ $option->location?->name }})</option>
                        @endforeach
                    </select>
                </label>

                @if ($service)
                    <div class="form-row">
                        <label class="field compact">
                            <span>Date</span>
                            <input type="date" wire:model.live="date" required>
                        </label>
                        <label class="field compact">
                            <span>People</span>
                            <input type="number" min="1" max="500" wire:model.live.debounce.400ms="participants" required>
                        </label>
                        @if ($service->kind->value === 'rental')
                            <label class="field compact">
                                <span>Length</span>
                                <select wire:model.live="durationMinutes">
                                    @forelse ($service->durations->sortBy('duration_minutes') as $duration)
                                        <option value="{{ $duration->duration_minutes }}">{{ $duration->name }}</option>
                                    @empty
                                        <option value="60">1 hour</option>
                                        <option value="120">2 hours</option>
                                        <option value="240">4 hours</option>
                                    @endforelse
                                </select>
                            </label>
                        @endif
                    </div>
                @endif
            </fieldset>

            @if ($service)
                <fieldset class="panel">
                    <legend>When</legend>
                    <p class="muted small booking-zone" data-booking-zone="{{ $zone }}">Times are in {{ $zone }}.</p>
                    @if ($service->kind->value === 'tour')
                        @if ($departures->isEmpty())
                            <p class="empty">No departures on this date. Schedule one under Services & prices.</p>
                        @else
                            <div class="slot-grid" role="radiogroup" aria-label="Departure">
                                @foreach ($departures as $row)
                                    @php
                                        $d = $row['departure'];
                                        $closed = (bool) ($row['closed'] ?? false);
                                        $full = $closed || ($row['seats_left'] !== null && $row['seats_left'] < $participants);
                                    @endphp
                                    <label @class(['slot', 'is-full' => $full, 'is-picked' => $departureId === $d->id])>
                                        <input type="radio" wire:model.live="departureId" value="{{ $d->id }}" @disabled($closed || ($full && ! $service->waitlist_enabled))>
                                        <span class="slot-time">{{ Display::rangeWithZone($d->starts_at, $d->ends_at, $zone) }}</span>
                                        <span class="slot-note">
                                            @if ($closed)
                                                Closed
                                            @elseif ($row['seats_left'] === null)
                                                Private
                                            @else
                                                {{ $row['seats_left'] }} seats left
                                            @endif
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    @else
                        @if ($times === [])
                            <p class="empty">No start times left on this date.</p>
                        @else
                            <div class="slot-grid" role="radiogroup" aria-label="Start time">
                                @foreach ($times as $slot)
                                    @php $value = $slot['starts_at']->toIso8601String(); @endphp
                                    <label @class(['slot', 'is-full' => ! $slot['available'], 'is-picked' => $startsAt === $value])>
                                        <input type="radio" wire:model.live="startsAt" value="{{ $value }}" @disabled($slot['closed'] || (! $slot['available'] && ! $service->waitlist_enabled))>
                                        <span class="slot-time">{{ $slot['label'] }}</span>
                                        <span class="slot-note">{{ $slot['closed'] ? 'Closed' : ($slot['available'] ? 'Open' : 'Booked') }}</span>
                                    </label>
                                @endforeach
                            </div>
                        @endif
                    @endif
                </fieldset>

                <fieldset class="panel">
                    <legend>Who</legend>
                    <div class="form-row">
                        <label class="field grow">
                            <span>Customer name</span>
                            <input type="text" wire:model="customerName" maxlength="120" autocomplete="off" required>
                        </label>
                        <label class="field grow">
                            <span>Email (optional)</span>
                            <input type="email" wire:model="customerEmail" maxlength="255" autocomplete="off">
                        </label>
                    </div>
                    @error('customerName')<p class="error">{{ $message }}</p>@enderror
                    @error('customerEmail')<p class="error">{{ $message }}</p>@enderror
                    <label class="field">
                        <span>Corporate account</span>
                        <select wire:model.live="corporateAccountId">
                            <option value="">No corporate account</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    @if ($quote)
                        <p data-corporate-quote="{{ $quote['total_cents'] }}">
                            <strong>{{ $quote['label'] }}</strong>
                            {{ Display::money($quote['total_cents'], $quote['currency']) }}
                            @if ($quote['detail'])
                                <span>{{ $quote['detail'] }}</span>
                            @endif
                        </p>
                    @endif
                    <label class="field">
                        <span>Guide</span>
                        <select wire:model="guideUserId">
                            <option value="">No guide</option>
                            @foreach ($guides as $guide)
                                <option value="{{ $guide->id }}">{{ $guide->name }}</option>
                            @endforeach
                        </select>
                    </label>
                </fieldset>

                <div aria-live="polite">
                    @if ($problem)
                        <p class="flash bad" role="alert">{{ $problem }}</p>
                    @endif
                </div>

                <div class="actions">
                    <button type="submit" class="button primary" wire:loading.attr="disabled">{{ $industry->holdButton() }}</button>
                    <button type="button" class="button" wire:click="saveQuote" wire:loading.attr="disabled">Save quote</button>
                    @if ($offerWaitlist)
                        <button type="button" class="button" wire:click="joinWaitlist">Add to waitlist instead</button>
                    @endif
                </div>
                <p class="muted small">{{ $industry->holdNote() }}</p>
            @endif
        </form>
    @endif
</div>
