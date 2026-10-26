@use('App\Domain\Fees\Disclosures\Money')
@use('App\Enums\AddOnPricing')
@php
    $catalog = $addOns ?? collect();
    $money = is_string($currency ?? null) && $currency !== '' ? $currency : 'USD';
    $oldAddOns = is_array($old['add_ons'] ?? null) ? $old['add_ons'] : [];
@endphp
@if ($catalog->isNotEmpty())
    <fieldset class="extras">
        <legend>Equipment and add-ons <span class="optional">(optional)</span></legend>
        @foreach ($catalog as $addOn)
            @php
                $previous = $oldAddOns[$addOn->id] ?? null;
                $previousQuantity = is_numeric($previous) ? (int) $previous : 0;
            @endphp
            @if ($addOn->pricing === AddOnPricing::Each)
                <label>
                    <span>{{ $addOn->name }}
                        <span class="optional">
                            {{ Money::format($addOn->amount_cents, $money) }} each
                            @if ($addOn->max_quantity)
                                · up to {{ $addOn->max_quantity }}
                            @endif
                        </span>
                    </span>
                    <input type="number" name="add_ons[{{ $addOn->id }}]" min="0" @if ($addOn->max_quantity) max="{{ $addOn->max_quantity }}" @endif inputmode="numeric" value="{{ $previousQuantity }}" data-addon="{{ $addOn->id }}">
                </label>
            @else
                <label>
                    <input type="checkbox" name="add_ons[{{ $addOn->id }}]" value="1" @checked($previousQuantity >= 1) data-addon="{{ $addOn->id }}">
                    <span>{{ $addOn->name }} <span class="optional">{{ Money::format($addOn->amount_cents, $money) }}{{ $addOn->pricing === AddOnPricing::PerPerson ? ' per person' : '' }}</span></span>
                </label>
            @endif
        @endforeach
    </fieldset>
@endif
