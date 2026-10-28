@php
    use App\Support\Display;
@endphp
<div class="page fee-report-page">
    <style>
        @media (max-width: 390px) {
            .fee-report-page { min-width: 0; max-width: 100%; }
            .fee-report-page .page-head,
            .fee-report-page .filters,
            .fee-report-page .stat-grid,
            .fee-report-page .report-list,
            .fee-report-page .report-list li,
            .fee-report-page .fee-tenant,
            .fee-report-page .fee-tenant * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Booking fees</h1>
            <p class="lede">These are the booking fees stored for this period, in UTC. Opening this page does not change fee rates and does not call Stripe. Compare asks Stripe for the period shown here. It does not write a price, a payment, a tax, a fee, or a refund.</p>
        </div>
    </header>

    @if ($problem)
        <p class="flash bad" role="alert">{{ $problem }}</p>
    @endif

    <form wire:submit="apply" class="filters">
        <label class="field compact">
            <span>From</span>
            <input type="date" wire:model="from" value="{{ $from }}" required>
        </label>
        <label class="field compact">
            <span>To</span>
            <input type="date" wire:model="to" value="{{ $to }}" required>
        </label>
        <button type="submit" class="button primary">Show</button>
    </form>

    <p>
        <button type="button" class="button" wire:click="compare">Compare this period with Stripe</button>
    </p>

    @if (is_array($revenue))
        <p class="lede">{{ $appliedFrom }} through {{ $appliedTo }}, UTC.</p>
        <section
            class="stat-grid"
            data-gross="{{ $revenue['gross_cents'] }}"
            data-refunded="{{ $revenue['refunded_cents'] }}"
            data-manual="{{ $revenue['manual_refunded_cents'] }}"
            data-net="{{ $revenue['net_cents'] }}"
        >
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Gross</span>
                    <span class="stat-value">{{ Display::money($revenue['gross_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Refunded</span>
                    <span class="stat-value">{{ Display::money($revenue['refunded_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Manual refunds</span>
                    <span class="stat-value">{{ Display::money($revenue['manual_refunded_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Net</span>
                    <span class="stat-value">{{ Display::money($revenue['net_cents']) }}</span>
                </span>
            </div>
        </section>
        <p>Manual refunds are already included in refunded. Net is gross minus refunded.</p>

        @if ($revenue['tenants'] === [])
            <p>No booking fees were recorded in this period.</p>
        @else
            @foreach ($revenue['tenants'] as $tenant)
                <article class="fee-tenant" data-tenant="{{ $tenant['tenant_id'] }}">
                    <h2>{{ $tenant['name'] }}</h2>
                    <p>
                        {{ Display::money($tenant['gross_cents']) }} gross,
                        {{ Display::money($tenant['refunded_cents']) }} refunded,
                        {{ Display::money($tenant['manual_refunded_cents']) }} manual,
                        {{ Display::money($tenant['net_cents']) }} net.
                    </p>
                    <ul class="report-list">
                        @foreach ($tenant['channels'] as $channel)
                            <li data-channel="{{ $channel['channel'] }}">
                                @php
                                    $channelLabel = match ($channel['channel']) {
                                        'direct' => 'Direct',
                                        'third_party' => 'Third party',
                                        default => 'Unrecorded',
                                    };
                                @endphp
                                <strong>{{ $channelLabel }}</strong>
                                <span>{{ Display::money($channel['gross_cents']) }} gross</span>
                                <span>{{ Display::money($channel['refunded_cents']) }} refunded</span>
                                <span>{{ Display::money($channel['net_cents']) }} net</span>
                                <span>{{ $channel['count'] }} {{ $channel['count'] === 1 ? 'fee' : 'fees' }}</span>
                            </li>
                        @endforeach
                    </ul>
                </article>
            @endforeach
        @endif
    @endif

    @if (is_array($comparison))
        <section class="panel" data-balanced="{{ $comparison['balanced'] ? 'yes' : 'no' }}">
            <h2>Stripe</h2>
            <p>{{ $comparison['balanced'] ? 'This period matches Stripe.' : 'This period does not match Stripe.' }}</p>
            <p>
                Stored {{ Display::money($comparison['stored_gross_cents']) }} gross,
                {{ Display::money($comparison['stored_refunded_cents']) }} refunded,
                {{ Display::money($comparison['stored_net_cents']) }} net.
                Stripe {{ Display::money($comparison['stripe_gross_cents']) }} gross,
                {{ Display::money($comparison['stripe_refunded_cents']) }} refunded,
                {{ Display::money($comparison['stripe_net_cents']) }} net.
            </p>
            @if ($comparison['differences'] !== [])
                <ul class="report-list">
                    @foreach ($comparison['differences'] as $row)
                        <li data-kind="{{ $row['kind'] }}">
                            <strong>{{ $row['label'] }}</strong>
                            @if ($row['workspace'] !== '')
                                <span>{{ $row['workspace'] }}</span>
                            @endif
                            <span>Stored {{ is_int($row['stored_amount_cents']) ? Display::money($row['stored_amount_cents']) : '—' }}</span>
                            <span>Stripe {{ is_int($row['stripe_amount_cents']) ? Display::money($row['stripe_amount_cents']) : '—' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
