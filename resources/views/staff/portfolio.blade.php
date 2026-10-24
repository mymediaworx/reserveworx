@php
    use App\Support\Display;

    $totals = $report['totals'];
    $maxGross = 0;

    foreach ($report['trend'] as $point) {
        $maxGross = max($maxGross, $point['gross_cents']);
    }
@endphp
<div class="page">
    <header class="page-head">
        <div>
            <h1>All businesses</h1>
            <p class="lede">{{ $from }} through {{ $to }}. Figures follow each location’s timezone.</p>
        </div>
    </header>

    @if (session('status'))
        <p class="flash ok" role="status">{{ session('status') }}</p>
    @endif

    <form method="get" action="/staff/portfolio" class="filters">
        <label class="field compact">
            <span>Business</span>
            <select name="business">
                <option value="all" @selected($selection === 'all')>All businesses</option>
                @foreach ($owned as $business)
                    <option value="{{ $business->id }}" @selected($selection === $business->id)>{{ $business->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Range</span>
            <select name="preset">
                <option value="7" @selected($preset === '7')>Last 7 days</option>
                <option value="30" @selected($preset === '30')>Last 30 days</option>
                <option value="90" @selected($preset === '90')>Last 90 days</option>
                <option value="custom" @selected($preset === 'custom')>Custom</option>
            </select>
        </label>
        <label class="field compact">
            <span>From</span>
            <input type="date" name="from" value="{{ $from }}" required>
        </label>
        <label class="field compact">
            <span>To</span>
            <input type="date" name="to" value="{{ $to }}" required>
        </label>
        <button type="submit" class="button primary">Apply</button>
        <a class="button" href="{{ $exportUrl }}">Download CSV</a>
    </form>

    <div class="stat-grid">
        <div class="stat-card">
            <span class="stat-strip">Bookings</span>
            <span class="stat-body"><span class="stat-value">{{ $totals['bookings'] }}</span></span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">Gross</span>
            <span class="stat-body"><span class="stat-value">{{ Display::money($totals['gross_cents']) }}</span></span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">Booking fees</span>
            <span class="stat-body"><span class="stat-value">{{ Display::money($totals['platform_fees_cents']) }}</span></span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">Refunds</span>
            <span class="stat-body"><span class="stat-value">{{ Display::money($totals['refunds_cents']) }}</span></span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">Net</span>
            <span class="stat-body"><span class="stat-value">{{ Display::money($totals['net_cents']) }}</span></span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">Cancellation rate</span>
            <span class="stat-body"><span class="stat-value">{{ Display::basisPoints($totals['cancellation_bps']) }}</span></span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">No-shows</span>
            <span class="stat-body">
                <span class="stat-value">{{ $totals['no_shows'] }}</span>
                <span class="muted small">{{ Display::basisPoints($totals['no_show_bps']) }}</span>
            </span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">Utilization</span>
            <span class="stat-body">
                @if ($totals['utilization_bps'] === null)
                    <span class="stat-value">Not enough hours</span>
                @else
                    <span class="stat-value">{{ Display::basisPoints($totals['utilization_bps']) }}</span>
                @endif
            </span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">New customers</span>
            <span class="stat-body"><span class="stat-value">{{ $totals['new_customers'] }}</span></span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">Returning customers</span>
            <span class="stat-body"><span class="stat-value">{{ $totals['returning_customers'] }}</span></span>
        </div>
        <div class="stat-card">
            <span class="stat-strip">Next 7 days</span>
            <span class="stat-body"><span class="stat-value">{{ $totals['upcoming'] }}</span></span>
        </div>
    </div>

    <section class="panel" aria-labelledby="trend-heading">
        <div class="panel-head">
            <h2 id="trend-heading">Trend</h2>
            <span class="muted small">Gross by day</span>
        </div>
        <svg class="trend-chart" viewBox="0 0 {{ max(count($report['trend']), 1) * 8 }} 100" role="img" aria-label="Gross revenue by day">
            @foreach ($report['trend'] as $index => $point)
                @php
                    $height = $maxGross === 0 ? 0 : (int) round(($point['gross_cents'] / $maxGross) * 92);
                    $y = 96 - $height;
                @endphp
                <rect x="{{ $index * 8 + 1 }}" y="{{ $y }}" width="6" height="{{ max($height, $point['gross_cents'] > 0 ? 1 : 0) }}" rx="1">
                    <title>{{ $point['date'] }}: {{ $point['bookings'] }} bookings, {{ Display::money($point['gross_cents']) }}</title>
                </rect>
            @endforeach
        </svg>
        <div class="table-scroll">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Bookings</th>
                        <th>Gross</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($report['trend'] as $point)
                        <tr>
                            <td>{{ $point['date'] }}</td>
                            <td>{{ $point['bookings'] }}</td>
                            <td>{{ Display::money($point['gross_cents']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    @if ($selection === 'all')
        <section class="panel" aria-labelledby="breakdown-heading">
            <div class="panel-head">
                <h2 id="breakdown-heading">By business</h2>
            </div>
            <div class="table-scroll">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Business</th>
                            <th>Bookings</th>
                            <th>Gross</th>
                            <th>Booking fees</th>
                            <th>Refunds</th>
                            <th>Net</th>
                            <th>Cancellation</th>
                            <th>No-shows</th>
                            <th>New</th>
                            <th>Returning</th>
                            <th>Next 7 days</th>
                            <th>Utilization</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['businesses'] as $business)
                            <tr>
                                <td>{{ $business['name'] }}</td>
                                <td>{{ $business['bookings'] }}</td>
                                <td>{{ Display::money($business['gross_cents']) }}</td>
                                <td>{{ Display::money($business['platform_fees_cents']) }}</td>
                                <td>{{ Display::money($business['refunds_cents']) }}</td>
                                <td>{{ Display::money($business['net_cents']) }}</td>
                                <td>{{ Display::basisPoints($business['cancellation_bps']) }}</td>
                                <td>{{ $business['no_shows'] }} ({{ Display::basisPoints($business['no_show_bps']) }})</td>
                                <td>{{ $business['new_customers'] }}</td>
                                <td>{{ $business['returning_customers'] }}</td>
                                <td>{{ $business['upcoming'] }}</td>
                                <td>{{ $business['utilization_bps'] === null ? 'Not enough hours' : Display::basisPoints($business['utilization_bps']) }}</td>
                            </tr>
                        @endforeach
                        @if (count($report['businesses']) > 1)
                            <tr>
                                <td>All businesses</td>
                                <td>{{ $totals['bookings'] }}</td>
                                <td>{{ Display::money($totals['gross_cents']) }}</td>
                                <td>{{ Display::money($totals['platform_fees_cents']) }}</td>
                                <td>{{ Display::money($totals['refunds_cents']) }}</td>
                                <td>{{ Display::money($totals['net_cents']) }}</td>
                                <td>{{ Display::basisPoints($totals['cancellation_bps']) }}</td>
                                <td>{{ $totals['no_shows'] }} ({{ Display::basisPoints($totals['no_show_bps']) }})</td>
                                <td>{{ $totals['new_customers'] }}</td>
                                <td>{{ $totals['returning_customers'] }}</td>
                                <td>{{ $totals['upcoming'] }}</td>
                                <td>{{ $totals['utilization_bps'] === null ? 'Not enough hours' : Display::basisPoints($totals['utilization_bps']) }}</td>
                            </tr>
                        @endif
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <p class="muted small">Bookings and gross count confirmed and no-show visits. Booking fees and refunds also include cancelled visits that start in the range. Net is gross, minus refunds, minus booking fees still kept. The next 7 days follow the business filter and ignore this range. Utilization uses stored daily summaries.</p>
</div>
