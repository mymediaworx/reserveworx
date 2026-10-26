@extends('widget.layout')
@section('title', $service->name)

@section('content')
    <p><a href="{{ url('/book/'.$token) }}">All services</a></p>
    <h1>{{ $service->name }}</h1>
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
    <form method="post" action="{{ url('/book/'.$token.'/services/'.$service->id) }}">
        @if ($service->kind->value === 'tour')
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
        @else
            <label>Date
                <input name="date" type="date" required value="{{ $old['date'] ?? '' }}">
            </label>
            <label>Start time
                <input name="start" type="time" required value="{{ $old['start'] ?? '' }}">
            </label>
            @if ($service->durations->isNotEmpty())
                <fieldset>
                    <legend>Length</legend>
                    @foreach ($service->durations as $duration)
                        <label>
                            <input type="radio" name="duration_id" value="{{ $duration->id }}" @checked(($old['duration_id'] ?? '') === $duration->id) required>
                            {{ $duration->name }} ({{ $duration->duration_minutes }} minutes)
                        </label>
                    @endforeach
                </fieldset>
            @else
                <label>Duration in minutes
                    <input name="duration_minutes" type="number" min="1" max="1440" required value="{{ $old['duration_minutes'] ?? 60 }}">
                </label>
            @endif
        @endif
        <label>Party size
            <input name="participants" type="number" min="1" required value="{{ $old['participants'] ?? 1 }}">
        </label>
        <label>Your name
            <input name="customer_name" type="text" required maxlength="120" autocomplete="name" value="{{ $old['customer_name'] ?? '' }}">
        </label>
        <label>Email
            <input name="customer_email" type="email" required maxlength="255" autocomplete="email" value="{{ $old['customer_email'] ?? '' }}">
        </label>
        <input type="hidden" name="idempotency_key" value="{{ $old['idempotency_key'] ?? (string) \Illuminate\Support\Str::uuid() }}">
        <button type="submit">Review price and hold this time</button>
    </form>
@endsection
