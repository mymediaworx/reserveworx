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
    $taxLines = isset($taxLines) && is_array($taxLines) ? $taxLines : [];
    $namedTaxes = false;

    foreach ($taxLines as $line) {
        if (is_array($line) && is_string($line['name'] ?? null) && is_numeric($line['cents'] ?? null)) {
            $namedTaxes = true;
            break;
        }
    }
@endphp
Subtotal {{ Money::format($subtotalCents, $currency) }}
@foreach ($operatorFees as $fee)
@if (is_array($fee) && is_string($fee['name'] ?? null) && is_numeric($fee['total_cents'] ?? null))
{{ $fee['name'] }} {{ Money::format((int) $fee['total_cents'], $currency) }}
@endif
@endforeach
@if ($lateFeeCents > 0)
Late fee {{ Money::format($lateFeeCents, $currency) }}
@endif
@if ($damageCents > 0)
Damage {{ Money::format($damageCents, $currency) }}
@endif
@if ($bookingFeeCents !== null)
{{ BookingFeeRefundTerms::LABEL }} {{ Money::format($bookingFeeCents, $currency) }}
@endif
@if ($namedTaxes)
@foreach ($taxLines as $line)
@if (is_array($line) && is_string($line['name'] ?? null) && is_numeric($line['cents'] ?? null))
{{ $line['name'] }} {{ Money::format((int) $line['cents'], $currency) }}
@endif
@endforeach
@else
Taxes {{ Money::format((int) $taxCents, $currency) }}
@endif
Total {{ Money::format($totalCents, $currency) }}
Paid {{ Money::format($paidCents, $currency) }}
@if ($refundedCents !== null)
Refunded {{ Money::format($refundedCents, $currency) }}
@if ($bookingFeeCents !== null)
{{ BookingFeeRefundTerms::LABEL }} refunded {{ Money::format($refundedBookingFeeCents ?? 0, $currency) }}
@endif
@endif
@if ($bookingFeeCents !== null && $refundTerms)
{{ BookingFeeRefundTerms::LABEL }} refunds
@foreach ($refundTerms->lines() as $line)
- {{ $line }}
@endforeach
Terms version {{ $refundTerms->version }}
@endif
