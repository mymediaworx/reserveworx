@extends('widget.layout')
@use('App\Services\Booking\PublicBookingCopy')
@section('title', 'Booking '.$booking->id)

@section('content')
    @php
        $copy = app(PublicBookingCopy::class)->for($booking);
    @endphp
    <h1>Booking {{ \App\Support\BookingReference::short($booking->id) }}</h1>
    <p class="optional" style="overflow-wrap:anywhere">Full reference: {{ $booking->id }}</p>
    <p>{{ $booking->service?->name }}</p>
    @if (is_string($booking->customer?->phone) && $booking->customer->phone !== '')
        <p data-customer-phone="1">Phone <a href="tel:{{ $booking->customer->phone }}">{{ $booking->customer->phone }}</a></p>
    @endif
    <p>Status: {{ $copy['status'] }}. {{ $copy['payment'] }}.</p>
    @include('widget.partials.priced-lines', [
        'serviceName' => $booking->service?->name,
        'serviceCents' => $serviceCents ?? 0,
        'addOnLines' => $addOnLines ?? [],
        'currency' => $currency ?? 'USD',
    ])
    @if ($copy['pickupFeeNote'] !== '')
        <p>{{ $copy['pickupFeeNote'] }}</p>
    @endif
    @php
        $shownTaxes = [];

        foreach ((isset($taxLines) && is_array($taxLines) ? $taxLines : []) as $taxLine) {
            if (is_array($taxLine) && is_string($taxLine['name'] ?? null) && is_numeric($taxLine['cents'] ?? null)) {
                $shownTaxes[] = $taxLine;
            }
        }
    @endphp
    @if ($shownTaxes !== [])
        <h2>Taxes</h2>
        <section class="checkout-summary">
            <dl>
                @include('disclosures.tax-lines', ['taxLines' => $shownTaxes, 'currency' => $currency ?? 'USD'])
            </dl>
        </section>
    @endif
    @if ($booking->status->value === 'confirmed')
        <p>Your booking is confirmed.</p>
    @else
        <p>A card payment is confirmed only after Stripe notifies ReserveWorx. This page does not complete the payment.</p>
    @endif
    @include('widget.partials.party')
    @if (is_string($waiverUrl ?? null))
        <div class="card" data-waiver-link>
            <h2>Sign your waiver</h2>
            <p class="price"><span>Sign before you arrive so check-in is quick.</span></p>
            <p><a href="{{ $waiverUrl }}">Sign now</a></p>
        </div>
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
