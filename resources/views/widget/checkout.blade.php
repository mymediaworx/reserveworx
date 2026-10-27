@extends('widget.layout')
@section('title', 'Review booking')

@section('content')
    <h1>Review before payment</h1>
    <p>{{ $booking->service?->name }} · reference {{ \App\Support\BookingReference::short($booking->id) }}</p>
    @if (is_string($booking->customer?->phone) && $booking->customer->phone !== '')
        <p data-customer-phone="1">Phone <a href="tel:{{ $booking->customer->phone }}">{{ $booking->customer->phone }}</a></p>
    @endif
    @include('widget.partials.party')
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
        $promoCode = is_string($priced['promo_code'] ?? null) ? $priced['promo_code'] : '';
        $groupBands = is_array($priced['party_bands'] ?? null) ? $priced['party_bands'] : [];
        $groupDetail = \App\Domain\Pricing\GroupPriceCopy::detail($groupBands, $booking->corporate_account_id === null);
        $groupPrice = $groupDetail === null ? null : [
            'label' => \App\Domain\Pricing\GroupPriceCopy::label($groupBands),
            'detail' => $groupDetail,
            'total_cents' => (int) ($priced['customer_total_cents'] ?? 0),
            'currency' => $snapshot->currency,
            'fee_included' => is_numeric($priced['customer_fee_line_cents'] ?? null) && (int) $priced['customer_fee_line_cents'] > 0,
        ];
    @endphp
    @include('widget.partials.group-price')
    @include('widget.partials.priced-lines', [
        'serviceName' => $booking->service?->name,
        'serviceCents' => $serviceCents,
        'addOnLines' => is_array($priced['add_ons'] ?? null) ? $priced['add_ons'] : [],
        'currency' => $snapshot->currency,
    ])
    @include('disclosures.checkout-summary', [
        'subtotalCents' => max(0, $serviceCents + $addOnCents),
        'discountCents' => $discountCents,
        'promoCode' => $promoCode,
        'operatorFees' => $operatorFees,
        'bookingFeeCents' => $snapshot->payload['customer_fee_line_cents'],
        'taxCents' => (int) $snapshot->payload['tax_cents'],
        'taxLines' => is_array($priced['tax_lines'] ?? null) ? $priced['tax_lines'] : [],
        'totalCents' => (int) $snapshot->payload['customer_total_cents'],
        'currency' => $snapshot->currency,
        'refundTerms' => $terms,
    ])
    @foreach ($cancellationLines as $line)
        <p>{{ $line }}</p>
    @endforeach
    @if ($promoUrl)
        <form method="post" action="{{ $promoUrl }}" class="promo-form">
            <label>
                Promo code <span class="muted">(optional)</span>
                <input type="text" name="promo_code" maxlength="40" autocomplete="off" value="{{ $promoValue }}">
            </label>
            <button type="submit">Apply code</button>
        </form>
    @endif
    @if ($waiverRequired && ! $waiverComplete)
        @include('widget.partials.waivers')
    @elseif ($waiverRequired)
        <p role="status" data-waivers="complete">Waiver signed.</p>
    @endif
    @if ($payUrl)
        {{-- target=_top: Stripe's payment page cannot load inside an embedded frame. --}}
        <form method="post" action="{{ $payUrl }}" target="_top">
            <button type="submit">Pay with card</button>
        </form>
        <p>The booking stays on hold until Stripe confirms the payment. Returning to this site is not proof of payment.</p>
    @endif
    @if (($payLocked ?? false) || ($pickupLocked ?? false))
        {{-- Shown, but not usable, until every waiver is signed. The server refuses payment too. --}}
        <div class="pay-locked" data-pay="locked">
            @if ($payLocked ?? false)
                <button type="button" disabled aria-disabled="true" aria-describedby="pay-locked-note">Pay with card</button>
            @endif
            @if ($pickupLocked ?? false)
                <button type="button" disabled aria-disabled="true" aria-describedby="pay-locked-note">Pay at pickup</button>
            @endif
            <p id="pay-locked-note">Sign the waiver above to continue to payment.</p>
        </div>
        <style>
            .pay-locked { display: grid; gap: .6rem; margin: 0 0 1rem; }
            .pay-locked button[disabled] { opacity: .5; cursor: not-allowed; max-width: 100%; }
        </style>
    @endif
    @if ($pickupUrl)
        <form method="post" action="{{ $pickupUrl }}">
            <button type="submit">Pay at pickup</button>
        </form>
        <p>Pay {{ $pickupLabel }} when you arrive. Pay at pickup does not include the online booking fee.</p>
    @endif
@endsection
