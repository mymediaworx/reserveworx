@extends('widget.layout')
@section('title', $service->name)

@section('content')
    <p><a href="{{ url('/book/'.$token) }}">All services</a></p>
    <h1>{{ $service->name }}</h1>
    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif
    <form method="post" action="{{ url('/book/'.$token.'/services/'.$service->id) }}">
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
