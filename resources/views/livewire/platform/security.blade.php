<div class="page security-page">
    <style>
        @media (max-width: 390px) {
            .security-page { min-width: 0; max-width: 100%; }
            .security-page .page-head,
            .security-page .security-list,
            .security-page .check-row,
            .security-page .check-row * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Security</h1>
            <p class="lede">These checks look at stored credentials, card columns, the session cookie, and CSRF. A sample token is written and thrown away. The check does not call Stripe and does not keep a row. <code>php artisan reserveworx:security</code> prints the same result.</p>
        </div>
    </header>

    <section class="notice" data-secure="{{ $secure ? 'yes' : 'no' }}">
        <p><strong>{{ $secure ? 'Secure' : 'Not secure' }}</strong>
            {{ $secure ? 'Every check passed.' : 'A check failed. The command exits with an error until it passes.' }}</p>
    </section>

    <ul class="security-list">
        @foreach ($checks as $check)
            <li class="check-row" data-check="{{ $check['key'] }}" data-state="{{ $check['state'] }}">
                <strong>{{ $check['label'] }}</strong>
                <span>{{ $check['detail'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
