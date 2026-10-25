@use('App\Domain\Fees\Disclosures\Money')
@php
    $currency = $currency ?? 'USD';
    $taxLines = isset($taxLines) && is_array($taxLines) ? $taxLines : [];
    $namedTaxes = false;

    foreach ($taxLines as $line) {
        if (is_array($line) && is_string($line['name'] ?? null) && is_numeric($line['cents'] ?? null)) {
            $namedTaxes = true;
            break;
        }
    }
@endphp
@if ($namedTaxes)
    @include('disclosures.tax-lines', ['taxLines' => $taxLines, 'currency' => $currency])
@else
    <div class="line taxes">
        <dt>Taxes</dt>
        <dd>{{ Money::format((int) $taxCents, $currency) }}</dd>
    </div>
@endif
