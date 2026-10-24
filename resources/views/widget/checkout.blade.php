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
    @php
        $priced = $snapshot->payload;
        $operatorFees = is_array($priced['operator_fees'] ?? null) ? $priced['operator_fees'] : [];
        $addOnCents = is_numeric($priced['add_ons_cents'] ?? null) ? (int) $priced['add_ons_cents'] : 0;
        $serviceCents = is_numeric($priced['services_cents'] ?? null) ? (int) $priced['services_cents'] : 0;
        $discountCents = is_numeric($priced['discount_cents'] ?? null) ? (int) $priced['discount_cents'] : 0;
    @endphp
    @include('disclosures.checkout-summary', [
        'subtotalCents' => max(0, $serviceCents - $discountCents + $addOnCents),
        'operatorFees' => $operatorFees,
        'bookingFeeCents' => $snapshot->payload['customer_fee_line_cents'],
        'taxCents' => (int) $snapshot->payload['tax_cents'],
        'totalCents' => (int) $snapshot->payload['customer_total_cents'],
        'currency' => $snapshot->currency,
        'refundTerms' => $terms,
    ])
    @if ($payUrl)
        {{-- target=_top: Stripe's payment page cannot load inside an embedded frame. --}}
        <form method="post" action="{{ $payUrl }}" target="_top">
            <button type="submit">Pay with card</button>
        </form>
        <p>The booking stays on hold until Stripe confirms the payment. Returning to this site is not proof of payment.</p>
    @endif
    @if ($pickupUrl)
        <form method="post" action="{{ $pickupUrl }}">
            <button type="submit">Pay at pickup</button>
        </form>
        <p>Pay {{ $pickupLabel }} when you arrive. Pay at pickup does not include the online booking fee.</p>
    @endif
@endsection
