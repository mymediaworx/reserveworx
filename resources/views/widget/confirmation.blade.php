@extends('widget.layout')
@section('title', 'Booking '.$booking->id)

@section('content')
    <h1>Booking <span style="overflow-wrap:anywhere">{{ $booking->id }}</span></h1>
    <p>{{ $booking->service?->name }}</p>
    <p>Status: {{ $booking->status->value }}. Payment: {{ $booking->payment_status->value }}.</p>
    @if ($booking->status->value === 'confirmed' && in_array($booking->payment_status->value, ['paid', 'deposit_paid', 'offline_paid'], true))
        <p>Your booking is confirmed.</p>
    @else
        <p>A card payment is confirmed only after Stripe notifies ReserveWorx. This page does not complete the payment.</p>
    @endif
@endsection
