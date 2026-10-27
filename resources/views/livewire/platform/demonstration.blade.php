<div class="page demonstration-page">
    <style>
        @media (max-width: 390px) {
            .demonstration-page { min-width: 0; max-width: 100%; }
            .demonstration-page .page-head,
            .demonstration-page .demo-list,
            .demonstration-page .demo-shop,
            .demonstration-page .demo-shop * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Multi-tenant demonstration</h1>
            <p class="lede">Harbor Demo and Inland Demo are two separate workspaces in this application. This page reads the records each one already stores. It does not create a workspace, and it does not write a booking, a price, a payment, a tax, a fee, or a refund. <code>php artisan reserveworx:demo</code> prints the same result.</p>
        </div>
    </header>

    <section class="notice" data-shown="{{ $shown ? 'yes' : 'no' }}">
        <p><strong>{{ $shown ? 'Shown' : 'Not shown' }}</strong> {{ $summary }}</p>
    </section>

    <ul class="demo-list">
        @foreach ($shops as $shop)
            <li class="demo-shop" data-shop="{{ $shop['slug'] }}" data-state="{{ $shop['state'] }}" data-isolated="{{ $shop['isolated'] }}" data-locations="{{ $shop['locations'] }}" data-services="{{ $shop['services'] }}" data-bookings="{{ $shop['bookings'] }}">
                <strong>{{ $shop['name'] }}</strong>
                @if ($shop['state'] === 'present')
                    <span>Status {{ $shop['status'] }}. Address {{ $shop['hostname'] === 'none' ? 'none verified' : str_replace(',', ', ', $shop['hostname']) }}.</span>
                    <span>{{ $shop['locations'] }} {{ $shop['locations'] === 1 ? 'location' : 'locations' }}. {{ $shop['services'] }} {{ $shop['services'] === 1 ? 'service' : 'services' }}. {{ $shop['bookings'] }} {{ $shop['bookings'] === 1 ? 'booking' : 'bookings' }}.</span>
                @endif
                <span>{{ $shop['detail'] }}</span>
            </li>
        @endforeach
    </ul>
</div>
