<div class="page load-sheet">
    <style>
        .load-sheet { min-width: 0; }
        .load-sheet .stop { display: grid; grid-template-columns: minmax(0, 1.4fr) repeat(3, minmax(0, 1fr)); gap: 0.35rem 0.75rem; align-items: baseline; }
        .load-sheet .stop-head { color: var(--muted); font-size: 0.8rem; }
        .load-sheet .load-total { margin-top: 0.75rem; }
        @media (max-width: 390px) {
            .load-sheet { min-width: 0; }
            .load-sheet .stop,
            .load-sheet .stop span,
            .load-sheet h1,
            .load-sheet h2 { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
            .load-sheet .stop { grid-template-columns: 1fr 1fr; }
            .load-sheet .stop .stop-name { grid-column: 1 / -1; }
            .load-sheet .button,
            .load-sheet .load-toolbar .button { width: 100%; }
        }
        @media print {
            .app-side,
            .app-bar,
            .tab-bar,
            .fab,
            .skip-link,
            .load-toolbar,
            .no-print { display: none !important; }
            .app,
            .app-shell,
            .app-main,
            .app-content,
            .load-sheet {
                display: block !important;
                background: #fff !important;
                min-height: 0 !important;
                padding: 0 !important;
                margin: 0 !important;
                width: auto !important;
                max-width: none !important;
                overflow: visible !important;
            }
            .load-sheet .panel { break-inside: avoid; page-break-inside: avoid; border: 0; box-shadow: none; }
        }
    </style>

    <header class="page-head">
        <div>
            <h1>Daily load sheet</h1>
            <p class="lede">How many of each fleet item to take to each location on {{ $sheet['date'] }} ({{ $zone }}). Built from that day’s bookings. Each stop uses that location’s time zone when it has one.</p>
        </div>
    </header>

    <div class="form-row load-toolbar no-print">
        <button type="button" class="button" wire:click="shift(-1)">Previous day</button>
        <button type="button" class="button" wire:click="today">Today</button>
        <button type="button" class="button" wire:click="shift(1)">Next day</button>
        <button type="button" class="button" onclick="window.print()">Print</button>
    </div>

    @if ($sheet['items'] === [])
        <section class="notice">
            <p>No shared fleet yet. Add one under Equipment, then mark the locations the truck delivers to.</p>
            <p><a class="button" href="/staff/setup/resources" wire:navigate>Open equipment</a></p>
        </section>
    @else
        @foreach ($sheet['items'] as $item)
            <section class="panel top-gap" data-fleet-item="{{ $item['id'] }}">
                <h2>{{ $item['name'] }}</h2>
                <p class="muted">Fleet count {{ $item['quantity'] }}. Load {{ $item['load'] }}.@if ($item['on_truck'] !== null) Still on the truck {{ $item['on_truck'] }}.@endif</p>
                @if ($item['locations'] === [])
                    <p class="empty">No locations are delivered from the fleet.</p>
                @else
                    <div class="stop stop-head" aria-hidden="true">
                        <span>Location</span>
                        <span>Booked</span>
                        <span>Walk-in hold</span>
                        <span>Load</span>
                    </div>
                    <ul class="rows">
                        @foreach ($item['locations'] as $stop)
                            <li class="stop" data-location="{{ $stop['id'] }}">
                                <span class="stop-name"><strong>{{ $stop['name'] }}</strong></span>
                                <span><span class="sr-only">Booked </span>{{ $stop['booked'] }}</span>
                                <span><span class="sr-only">Walk-in hold </span>{{ $stop['walk_in'] }}</span>
                                <span><span class="sr-only">Load </span>{{ $stop['load'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
        <p class="muted small load-total">A walk-in hold is delivered even when nobody has booked it yet. Locations that keep their own gear are not on this sheet.</p>
    @endif
</div>
