@extends('widget.layout')
@section('title', 'Review booking')

@section('content')
    <h1>Review before payment</h1>
    <p>{{ $booking->service?->name }} · reference {{ $booking->id }}</p>
    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif
    @if (! $hold)
        <p class="error" role="alert">This hold has expired. Choose another time.</p>
    @endif
    @include('disclosures.checkout-summary', [
        'subtotalCents' => (int) $snapshot->payload['services_cents'] - (int) $snapshot->payload['discount_cents'],
        'bookingFeeCents' => $snapshot->payload['customer_fee_line_cents'],
        'taxCents' => (int) $snapshot->payload['tax_cents'],
        'totalCents' => (int) $snapshot->payload['customer_total_cents'],
        'currency' => $snapshot->currency,
        'refundTerms' => $terms,
    ])
    @if ($payUrl)
        <form method="post" action="{{ $payUrl }}">
            <button type="submit">Pay with card</button>
        </form>
        <p>The booking stays on hold until Stripe confirms the payment. Returning to this site is not proof of payment.</p>
    @endif
@endsection
