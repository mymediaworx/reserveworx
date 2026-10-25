{{--
    Receipt and confirmation fee section (spec Section 7.6 items 5 and 6).

    @param int $subtotalCents
    @param list<array{name?: string, total_cents?: int|string}>|null $operatorFees
    @param int|null $bookingFeeCents  Customer-paid booking fee; null in operator-absorbed mode.
    @param int $taxCents
    @param list<array{name?: string, cents?: int|string}>|null $taxLines
    @param int $lateFeeCents  Applied late-return fee. This is not the booking fee.
    @param int $damageCents  Damage added from an incident report. This is not the booking fee.
    @param int $totalCents
    @param int $paidCents
    @param int|null $refundedCents  Total refunded to the customer, for refund receipts.
    @param int|null $refundedBookingFeeCents  Booking fee within that refund, itemized on refund receipts.
    @param string $currency
    @param \App\Models\DisclosureVersion|null $refundTerms  The version the customer saw at checkout.
--}}
@use('App\Domain\Fees\Disclosures\BookingFeeRefundTerms')
@use('App\Domain\Fees\Disclosures\Money')
@php
    $currency = $currency ?? 'USD';
    $operatorFees = $operatorFees ?? [];
    $bookingFeeCents = $bookingFeeCents ?? null;
    $refundTerms = $refundTerms ?? null;
    $refundedCents = $refundedCents ?? null;
    $refundedBookingFeeCents = $refundedBookingFeeCents ?? null;
    $lateFeeCents = $lateFeeCents ?? 0;
    $damageCents = $damageCents ?? 0;

    if ($bookingFeeCents !== null && $refundTerms === null) {
        throw new \LogicException('Customer-paid receipts must show the booking fee refund terms.');
    }
@endphp
<section class="receipt-summary">
    <dl>
        <div class="line subtotal">
            <dt>Subtotal</dt>
            <dd>{{ Money::format($subtotalCents, $currency) }}</dd>
        </div>
        @foreach ($operatorFees as $fee)
            @if (is_array($fee) && is_string($fee['name'] ?? null) && is_numeric($fee['total_cents'] ?? null))
                <div class="line operator-fee">
                    <dt>{{ $fee['name'] }}</dt>
                    <dd>{{ Money::format((int) $fee['total_cents'], $currency) }}</dd>
                </div>
            @endif
        @endforeach
        @if ($lateFeeCents > 0)
            <div class="line late-fee">
                <dt>Late fee</dt>
                <dd>{{ Money::format($lateFeeCents, $currency) }}</dd>
            </div>
        @endif
        @if ($damageCents > 0)
            <div class="line damage">
                <dt>Damage</dt>
                <dd>{{ Money::format($damageCents, $currency) }}</dd>
            </div>
        @endif
        @if ($bookingFeeCents !== null)
            <div class="line booking-fee">
                <dt>{{ BookingFeeRefundTerms::LABEL }}</dt>
                <dd>{{ Money::format($bookingFeeCents, $currency) }}</dd>
            </div>
        @endif
        @include('disclosures.partials.tax-or-lines')
        <div class="line total">
            <dt>Total</dt>
            <dd>{{ Money::format($totalCents, $currency) }}</dd>
        </div>
        <div class="line paid">
            <dt>Paid</dt>
            <dd>{{ Money::format($paidCents, $currency) }}</dd>
        </div>
        @if ($refundedCents !== null)
            <div class="line refunded">
                <dt>Refunded</dt>
                <dd>{{ Money::format($refundedCents, $currency) }}</dd>
            </div>
            @if ($bookingFeeCents !== null)
                <div class="line refunded-booking-fee">
                    <dt>{{ BookingFeeRefundTerms::LABEL }} refunded</dt>
                    <dd>{{ Money::format($refundedBookingFeeCents ?? 0, $currency) }}</dd>
                </div>
            @endif
        @endif
    </dl>

    @if ($bookingFeeCents !== null)
        @include('disclosures.refund-terms', ['refundTerms' => $refundTerms])
    @endif
</section>
