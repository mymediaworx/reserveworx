@extends('widget.layout')
@use('App\Services\Booking\PublicBookingCopy')
@section('title', 'Booking '.$booking->id)

@section('content')
    @php
        $copy = app(PublicBookingCopy::class)->for($booking);
    @endphp
    <h1>Booking {{ $booking->id }}</h1>
    <p>{{ $booking->service?->name }}</p>
    <p>Status: {{ $copy['status'] }}. {{ $copy['payment'] }}.</p>
    @if ($copy['pickupFeeNote'] !== '')
        <p>{{ $copy['pickupFeeNote'] }}</p>
    @endif
    <p>Starts {{ $booking->starts_at->toIso8601String() }}.</p>
    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif
    @if ($canCancel)
        <form method="post" action="{{ $cancelUrl }}">
            <button type="submit">Cancel booking</button>
        </form>
        <p>Cancelling releases the time. It does not refund a card payment.</p>
    @elseif ($cancelMessage)
        <p>{{ $cancelMessage }}</p>
    @endif
    @if ($booking->status->value === 'confirmed')
        <form method="post" action="{{ $rescheduleUrl }}">
            <label>New date
                <input name="date" type="date" required>
            </label>
            <label>New start
                <input name="start" type="time" required>
            </label>
            <label>Duration in minutes
                <input name="duration_minutes" type="number" min="1" max="1440" required value="60">
            </label>
            <button type="submit">Reschedule</button>
        </form>
    @endif
@endsection
