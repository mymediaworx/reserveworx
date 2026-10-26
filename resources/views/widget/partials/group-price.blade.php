@if (! empty($groupPrice))
    <p class="group-price" data-group-price="{{ $groupPrice['total_cents'] }}">
        <strong>{{ $groupPrice['label'] }}</strong>
        {{ \App\Domain\Fees\Disclosures\Money::format($groupPrice['total_cents'], $groupPrice['currency']) }}
        <span>{{ $groupPrice['detail'] }}</span>
        @if ($groupPrice['fee_included'])
            <span>Booking fee included</span>
        @endif
    </p>
@endif
