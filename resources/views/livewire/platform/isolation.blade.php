<div class="page isolation-page">
    <style>
        @media (max-width: 390px) {
            .isolation-page { min-width: 0; max-width: 100%; }
            .isolation-page .page-head,
            .isolation-page .isolation-list,
            .isolation-page .check-row,
            .isolation-page .check-row * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Isolation</h1>
            <p class="lede">These checks write a sample customer and throw it away. Another workspace must not see that customer, and neither must a request with no workspace. The check does not call Stripe and does not keep a row. <code>php artisan reserveworx:isolation</code> prints the same result.</p>
        </div>
    </header>

    <section class="notice" data-isolated="{{ $isolated ? 'yes' : 'no' }}">
        <p><strong>{{ $isolated ? 'Isolated' : 'Not isolated' }}</strong>
            {{ $isolated ? 'Every check passed.' : 'A check failed. The command exits with an error until it passes.' }}</p>
    </section>

    <ul class="isolation-list">
        @foreach ($checks as $check)
            <li class="check-row" data-check="{{ $check['key'] }}" data-state="{{ $check['state'] }}">
                <strong>{{ $check['label'] }}</strong>
                <span>{{ $check['detail'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
