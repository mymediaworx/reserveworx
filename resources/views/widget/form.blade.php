@extends('widget.layout')
@section('title', $service->name)

@section('content')
    <p><a href="{{ url('/book/'.$token) }}">All services</a></p>
    <h1>{{ $service->name }}</h1>
    @include('widget.partials.group-price')
    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif
    @if ($suggestions !== [])
        <h2>Other times and equipment</h2>
        <ul>
            @foreach ($suggestions as $suggestion)
                <li>{{ $suggestion }}</li>
            @endforeach
        </ul>
    @endif
    @if ($offerWaitlist ?? false)
        <div class="waitlist-join">
            <style>
                .waitlist-join, .waitlist-join * { min-width: 0; overflow-wrap: anywhere; }
                @media (max-width: 390px) {
                    .waitlist-join form, .waitlist-join button, .waitlist-join input { max-width: 100%; }
                }
            </style>
            <h2>Join waitlist</h2>
            <p>This time is full. Leave your name and we will email a link you can use to leave the list.</p>
            <form method="post" action="{{ url('/book/'.$token.'/services/'.$service->id.'/waitlist') }}">
                <label>Your name
                    <input name="customer_name" type="text" required maxlength="120" autocomplete="name" value="{{ $old['customer_name'] ?? '' }}">
                </label>
                <label>Email
                    <input name="customer_email" type="email" required maxlength="255" autocomplete="email" value="{{ $old['customer_email'] ?? '' }}">
                </label>
                <label>Phone <span class="optional">(optional)</span>
                    <input name="customer_phone" type="tel" maxlength="255" autocomplete="tel" value="{{ $old['customer_phone'] ?? '' }}">
                </label>
                <label>Party size
                    <input name="participants" type="number" min="1" required value="{{ $old['participants'] ?? 1 }}">
                </label>
                @if (($old['departure_id'] ?? '') !== '')
                    <input type="hidden" name="departure_id" value="{{ $old['departure_id'] }}">
                @endif
                <input type="hidden" name="date" value="{{ $old['date'] ?? '' }}">
                <input type="hidden" name="start" value="{{ $old['start'] ?? '' }}">
                @if (($old['duration_id'] ?? '') !== '')
                    <input type="hidden" name="duration_id" value="{{ $old['duration_id'] }}">
                @else
                    <input type="hidden" name="duration_minutes" value="{{ $old['duration_minutes'] ?? 60 }}">
                @endif
                <input type="hidden" name="idempotency_key" value="{{ $old['idempotency_key'] ?? (string) \Illuminate\Support\Str::uuid() }}">
                <button type="submit">Join waitlist</button>
            </form>
        </div>
    @endif
    @if ($service->kind->value === 'tour')
        @if ($hasPublicGroupTier ?? false)
            <form method="get" action="{{ url('/book/'.$token.'/services/'.$service->id) }}" class="time-finder">
                <label>Party size
                    <input name="participants" type="number" min="1" required value="{{ $old['participants'] ?? '' }}">
                </label>
                <button type="submit">Show group price</button>
            </form>
        @endif
        <form method="post" action="{{ url('/book/'.$token.'/services/'.$service->id) }}">
            @if ($departureChoices === [])
                <p>No upcoming departures. Check back when a departure is scheduled.</p>
            @else
                <label>Departure
                    <select name="departure_id" required>
                        <option value="">Choose a departure</option>
                        @foreach ($departureChoices as $choice)
                            <option value="{{ $choice['id'] }}" @selected(($old['departure_id'] ?? '') === $choice['id'])>{{ $choice['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label>Party size
                <input name="participants" type="number" min="1" required value="{{ $old['participants'] ?? 1 }}">
            </label>
            @include('widget.partials.add-ons')
            <label>Your name
                <input name="customer_name" type="text" required maxlength="120" autocomplete="name" value="{{ $old['customer_name'] ?? '' }}">
            </label>
            <label>Email
                <input name="customer_email" type="email" required maxlength="255" autocomplete="email" value="{{ $old['customer_email'] ?? '' }}">
            </label>
            <input type="hidden" name="idempotency_key" value="{{ $old['idempotency_key'] ?? (string) \Illuminate\Support\Str::uuid() }}">
            <button type="submit">Review price and hold this time</button>
        </form>
    @else
        @php
            $openCount = collect($slots ?? [])->where('available', true)->count();
        @endphp
        <form method="get" action="{{ url('/book/'.$token.'/services/'.$service->id) }}" class="time-finder">
            <label>Date
                <input name="date" type="date" required min="{{ $today }}" value="{{ $old['date'] ?? '' }}">
            </label>
            @if ($service->durations->isNotEmpty())
                <fieldset>
                    <legend>Length</legend>
                    @foreach ($service->durations as $duration)
                        <label>
                            <input type="radio" name="duration_id" value="{{ $duration->id }}" @checked(($old['duration_id'] ?? '') === $duration->id || ($loop->first && ($old['duration_id'] ?? '') === '')) required>
                            {{ $duration->name }} ({{ \App\Support\Display::minutes($duration->duration_minutes) }})
                        </label>
                    @endforeach
                </fieldset>
            @else
                <label>Duration in minutes
                    <input name="duration_minutes" type="number" min="1" max="1440" required value="{{ $old['duration_minutes'] ?? 60 }}">
                </label>
            @endif
            <label>Party size
                <input name="participants" type="number" min="1" required value="{{ $old['participants'] ?? 1 }}">
            </label>
            <button type="submit" @class(['secondary' => $slots !== null])>{{ $slots === null ? 'Show available times' : 'Update times' }}</button>
        </form>

        @if ($slots !== null)
            <form method="post" action="{{ url('/book/'.$token.'/services/'.$service->id) }}" class="time-picker">
                <input type="hidden" name="date" value="{{ $old['date'] ?? '' }}">
                @if (($old['duration_id'] ?? '') !== '')
                    <input type="hidden" name="duration_id" value="{{ $old['duration_id'] }}">
                @else
                    <input type="hidden" name="duration_minutes" value="{{ $old['duration_minutes'] ?? 60 }}">
                @endif
                <input type="hidden" name="participants" value="{{ $old['participants'] ?? 1 }}">
                @if ($openCount === 0)
                    <p class="notice" role="status" data-open-times="0">No open times on this date for that length and party. Try another date or a shorter length.</p>
                @else
                    <fieldset class="slots" data-open-times="{{ $openCount }}">
                        <legend>Start time</legend>
                        @foreach ($slots as $slot)
                            <label @class(['is-full' => ! $slot['available']])>
                                <input type="radio" name="start" value="{{ $slot['value'] }}" required @disabled(! $slot['available']) @checked(($old['start'] ?? '') === $slot['value'] && $slot['available'])>
                                <span>{{ $slot['label'] }}</span>
                                @unless ($slot['available'])
                                    <span class="slot-note">{{ $slot['closed'] ? 'Closed' : 'Booked' }}</span>
                                @endunless
                            </label>
                        @endforeach
                    </fieldset>
                    @include('widget.partials.add-ons')
                    <label>Your name
                        <input name="customer_name" type="text" required maxlength="120" autocomplete="name" value="{{ $old['customer_name'] ?? '' }}">
                    </label>
                    <label>Email
                        <input name="customer_email" type="email" required maxlength="255" autocomplete="email" value="{{ $old['customer_email'] ?? '' }}">
                    </label>
                    <input type="hidden" name="idempotency_key" value="{{ $old['idempotency_key'] ?? (string) \Illuminate\Support\Str::uuid() }}">
                    <button type="submit">Review price and hold this time</button>
                @endif
            </form>
        @else
            <p class="muted">Choose a date, length, and party size to see the open start times.</p>
        @endif
    @endif
@endsection
