{{--
    Booking fee refund terms (spec Section 7.6 items 4 and 5).
    @param \App\Models\DisclosureVersion|null $refundTerms
--}}
@if ($refundTerms)
    <section class="booking-fee-refund-terms" data-disclosure-version="{{ $refundTerms->version }}">
        <h3>{{ \App\Domain\Fees\Disclosures\BookingFeeRefundTerms::LABEL }} refunds</h3>
        <ul>
            @foreach ($refundTerms->lines() as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
        <p class="disclosure-version">Terms version {{ $refundTerms->version }}</p>
    </section>
@endif
