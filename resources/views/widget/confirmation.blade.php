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
    @if (is_string($checkInQr ?? null) && $checkInQr !== '')
        <h2>Check-in code</h2>
        <p>Show this code when you arrive.</p>
        <div class="confirm-qr">{!! $checkInQr !!}</div>
        @if (is_string($checkInUrl ?? null) && $checkInUrl !== '')
            <p class="confirm-url">{{ $checkInUrl }}</p>
        @endif
        <style>
            .confirm-qr { max-width: 11rem; width: 100%; }
            .confirm-qr svg { width: 100%; height: auto; display: block; }
            .confirm-url { overflow-wrap: anywhere; }
            @media (max-width: 390px) {
                .confirm-qr { max-width: 100%; }
            }
        </style>
    @endif
@endsection
