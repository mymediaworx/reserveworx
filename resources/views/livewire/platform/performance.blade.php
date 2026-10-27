<div class="page performance-page">
    <style>
        @media (max-width: 390px) {
            .performance-page { min-width: 0; max-width: 100%; }
            .performance-page .page-head,
            .performance-page .performance-list,
            .performance-page .check-row,
            .performance-page .check-row * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Performance</h1>
            <p class="lede">These numbers are from this server. The check renders the product page, signup, and one booking page, then throws that work away. It does not call the public internet, and it does not keep a price, a payment, a tax, a fee, or a refund. <code>php artisan reserveworx:performance</code> prints the same result. Run it on the SiteGround server to measure that machine.</p>
        </div>
    </header>

    <section class="notice" data-within="{{ $within ? 'yes' : 'no' }}">
        <p><strong>{{ $within ? 'Within budget' : 'Over budget' }}</strong>
            {{ $within ? 'Every measured page stayed inside its query budget, and memory is at least 128M.' : 'A check failed. The command exits with an error until it passes.' }}</p>
    </section>

    <ul class="performance-list">
        @foreach ($checks as $check)
            <li class="check-row" data-check="{{ $check['key'] }}" data-state="{{ $check['state'] }}">
                <strong>{{ $check['label'] }}</strong>
                <span>{{ $check['detail'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
