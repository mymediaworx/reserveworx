<div class="page limits-page">
    <style>
        @media (max-width: 390px) {
            .limits-page { min-width: 0; max-width: 100%; }
            .limits-page .page-head,
            .limits-page .limits-list,
            .limits-page .check-row,
            .limits-page .check-row * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Limits</h1>
            <p class="lede">Whether scheduled work stays inside the shared-host window on this server. The check does not print a secret and does not keep a price, a payment, a tax, a fee, or a refund. <code>php artisan reserveworx:limits</code> prints the same result.</p>
        </div>
    </header>

    <section class="notice"
        data-within="{{ $within ? 'yes' : 'no' }}"
        data-window="{{ $window }}"
        data-configured="{{ $configured }}"
        data-worker="{{ $worker }}"
        data-jobs="{{ $jobsOk ? 'yes' : 'no' }}"
        data-overlap="{{ $overlapOk ? 'yes' : 'no' }}">
        <p><strong>{{ $within ? 'Within the limit' : 'Outside the limit' }}</strong>
            {{ $within ? 'The configured time, every job timeout, the queue worker, and the schedule stay inside the shared-host window.' : 'A check failed. The command exits with an error until it passes.' }}</p>
    </section>

    <ul class="limits-list">
        @foreach ($checks as $check)
            <li class="check-row" data-check="{{ $check['key'] }}" data-state="{{ $check['state'] }}">
                <strong>{{ $check['label'] }}</strong>
                <span>{{ $check['detail'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
