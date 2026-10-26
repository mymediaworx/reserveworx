@use('App\Domain\Fees\Disclosures\Money')
@php
    $lines = [];

    foreach ($addOnLines ?? [] as $line) {
        if (is_array($line) && is_string($line['name'] ?? null) && is_numeric($line['total_cents'] ?? null)) {
            $lines[] = $line;
        }
    }

    $money = is_string($currency ?? null) && $currency !== '' ? $currency : 'USD';
    $serviceAmount = is_numeric($serviceCents ?? null) ? (int) $serviceCents : 0;
    $serviceLabel = is_string($serviceName ?? null) && $serviceName !== '' ? $serviceName : 'Service';
@endphp
@if ($lines !== [])
    <h2>Equipment and add-ons</h2>
    <section class="checkout-summary" data-chosen-addons>
        <dl>
            <div class="line">
                <dt>{{ $serviceLabel }}</dt>
                <dd>{{ Money::format($serviceAmount, $money) }}</dd>
            </div>
            @foreach ($lines as $line)
                @php
                    $quantity = is_numeric($line['quantity'] ?? null) ? (int) $line['quantity'] : 1;
                @endphp
                <div class="line">
                    <dt>{{ $line['name'] }}@if ($quantity > 1) × {{ $quantity }}@endif</dt>
                    <dd>{{ Money::format((int) $line['total_cents'], $money) }}</dd>
                </div>
            @endforeach
        </dl>
    </section>
@endif
