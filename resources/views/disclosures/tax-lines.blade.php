{{--
    Named tax lines from the booking location's rates.

    @param list<array{name?: string, cents?: int|string}> $taxLines
    @param string $currency
--}}
@use('App\Domain\Fees\Disclosures\Money')
@php
    $currency = $currency ?? 'USD';
    $taxLines = isset($taxLines) && is_array($taxLines) ? $taxLines : [];
@endphp
@foreach ($taxLines as $line)
    @if (is_array($line) && is_string($line['name'] ?? null) && is_numeric($line['cents'] ?? null))
        <div class="line tax">
            <dt>{{ $line['name'] }}</dt>
            <dd>{{ Money::format((int) $line['cents'], $currency) }}</dd>
        </div>
    @endif
@endforeach
