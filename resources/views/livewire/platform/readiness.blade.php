<div class="page readiness-page">
    <style>
        @media (max-width: 390px) {
            .readiness-page { min-width: 0; max-width: 100%; }
            .readiness-page .page-head,
            .readiness-page .readiness-list,
            .readiness-page .check-row,
            .readiness-page .check-row * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Production readiness</h1>
            <p class="lede">These are the checks this server can answer. The report does not run the test suite, call Stripe, or change a price, a tax, a fee, or a refund. <code>php artisan reserveworx:readiness</code> prints the same result.</p>
        </div>
    </header>

    <section class="notice" data-ready="{{ $ready ? 'yes' : 'no' }}">
        <p><strong>{{ $ready ? 'Ready' : 'Not ready' }}</strong>
            {{ $ready ? 'Every check passed or is noted for production only.' : 'A check failed. The command exits with an error until it passes.' }}</p>
    </section>

    <ul class="readiness-list">
        @foreach ($checks as $check)
            <li class="check-row" data-check="{{ $check['key'] }}" data-state="{{ $check['state'] }}">
                <strong>{{ $check['label'] }}</strong>
                <span>{{ $check['detail'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
