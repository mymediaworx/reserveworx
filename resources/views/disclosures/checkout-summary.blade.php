{{--
    Itemized checkout summary (spec Section 7.6 items 2, 3 and 4).

    @param int $subtotalCents
    @param int|null $discountCents
    @param string|null $promoCode
    @param list<array{name?: string, total_cents?: int|string}>|null $operatorFees
    @param int|null $bookingFeeCents  Customer-paid booking fee; null in operator-absorbed mode.
    @param int $taxCents
    @param int $totalCents
    @param string $currency
    @param \App\Models\DisclosureVersion|null $refundTerms  Required in customer-paid mode.
--}}
@use('App\Domain\Fees\Disclosures\BookingFeeRefundTerms')
@use('App\Domain\Fees\Disclosures\Money')
@php
    $currency = $currency ?? 'USD';
    $discountCents = isset($discountCents) && is_numeric($discountCents) ? (int) $discountCents : 0;
    $promoCode = isset($promoCode) && is_string($promoCode) ? $promoCode : '';
    $operatorFees = $operatorFees ?? [];
    $bookingFeeCents = $bookingFeeCents ?? null;
    $refundTerms = $refundTerms ?? null;

    if ($bookingFeeCents !== null && $refundTerms === null) {
        throw new \LogicException('Customer-paid checkout must show the booking fee refund terms before payment.');
    }
@endphp
<section class="checkout-summary">
    <dl>
        <div class="line subtotal">
            <dt>Subtotal</dt>
            <dd>{{ Money::format($subtotalCents, $currency) }}</dd>
        </div>
        @if ($discountCents > 0)
            <div class="line discount">
                <dt>Discount{{ $promoCode !== '' ? ' ('.$promoCode.')' : '' }}</dt>
                <dd>{{ Money::format(-$discountCents, $currency) }}</dd>
            </div>
        @endif
        @foreach ($operatorFees as $fee)
            @if (is_array($fee) && is_string($fee['name'] ?? null) && is_numeric($fee['total_cents'] ?? null))
                <div class="line operator-fee">
                    <dt>{{ $fee['name'] }}</dt>
                    <dd>{{ Money::format((int) $fee['total_cents'], $currency) }}</dd>
                </div>
            @endif
        @endforeach
        @if ($bookingFeeCents !== null)
            <div class="line booking-fee">
                <dt>{{ BookingFeeRefundTerms::LABEL }}</dt>
                <dd>{{ Money::format($bookingFeeCents, $currency) }}</dd>
            </div>
        @endif
        <div class="line taxes">
            <dt>Taxes</dt>
            <dd>{{ Money::format($taxCents, $currency) }}</dd>
        </div>
        <div class="line total">
            <dt>Total</dt>
            <dd>{{ Money::format($totalCents, $currency) }}</dd>
        </div>
    </dl>

    @if ($bookingFeeCents !== null)
        @include('disclosures.refund-terms', ['refundTerms' => $refundTerms])
    @endif
</section>
