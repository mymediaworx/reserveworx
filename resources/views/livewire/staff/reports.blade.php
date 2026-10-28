@php
    use App\Support\Display;
@endphp
<div class="page reports-page">
    <style>
        .reports-page { min-width: 0; }
        .reports-page .stat-card { min-height: 0; }
        .reports-page .stat-title { padding-right: 0; overflow-wrap: anywhere; }
        .reports-page .stat-value { font-size: 1.6rem; overflow-wrap: anywhere; }
        .reports-page .report-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 0.5rem; }
        .reports-page .report-list li { display: flex; flex-wrap: wrap; gap: 0.25rem 0.75rem; align-items: baseline; min-width: 0; }
        .reports-page .report-list li > * { min-width: 0; overflow-wrap: anywhere; }
        .reports-note { margin: 0 0 1rem; }
        @media (max-width: 390px) {
            .reports-page { min-width: 0; }
            .reports-page .stat-grid { grid-template-columns: minmax(0, 1fr); }
            .reports-page .filters { display: grid; min-width: 0; }
            .reports-page .filters .field,
            .reports-page .filters .button { width: 100%; min-width: 0; }
            .reports-page .report-list,
            .reports-page .report-list li,
            .reports-page .report-list span,
            .reports-page .report-list strong,
            .reports-page .when { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
        }
    </style>

    <header class="page-head">
        <div>
            <h1>Reports</h1>
            <p class="lede">{{ $from }} through {{ $to }}. Dates follow each location’s timezone.</p>
        </div>
    </header>

    <form method="get" action="/staff/reports" class="filters">
        <label class="field compact">
            <span>From</span>
            <input type="date" name="from" value="{{ $from }}" required>
        </label>
        <label class="field compact">
            <span>To</span>
            <input type="date" name="to" value="{{ $to }}" required>
        </label>
        @if ($locations->isNotEmpty())
            <label class="field compact">
                <span>Location</span>
                <select name="location">
                    <option value="" @selected($locationId === '')>All locations</option>
                    @foreach ($locations as $option)
                        <option value="{{ $option->id }}" @selected($locationId === $option->id)>{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <button type="submit" class="button primary">Apply</button>
        <a class="button" href="{{ $exportUrl }}">Download CSV</a>
    </form>

    <p class="reports-note muted">Gross booking value is the service total on confirmed and no-show bookings. It is not the money captured on a card, and it is not an offline payment.</p>

    <div
        id="report-figures"
        data-gross="{{ $report['gross_cents'] }}"
        data-card="{{ $report['card_cents'] }}"
        data-offline="{{ $report['offline_cents'] }}"
        data-refunds="{{ $report['refunds_cents'] }}"
        data-tax="{{ $report['tax_cents'] }}"
        data-fee="{{ $report['fee_cents'] }}"
        data-fee-refunded="{{ $report['fee_refunded_cents'] }}"
        data-cancellation="{{ $report['cancellation_bps'] ?? '' }}"
        data-no-show="{{ $report['no_show_bps'] ?? '' }}"
        data-new-customers="{{ $report['new_customers'] }}"
        data-repeat-customers="{{ $report['repeat_customers'] }}"
        data-summary="{{ $report['summary_ready'] ? 'ready' : 'empty' }}"
        data-utilization="{{ $report['utilization_bps'] ?? '' }}"
        data-conversion="{{ $report['conversion_bps'] ?? '' }}"
        data-average-order="{{ $report['average_order_cents'] ?? '' }}"
        data-revenue-per-hour="{{ $report['revenue_per_resource_hour_cents'] ?? '' }}"
    >
        <div class="stat-grid">
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Gross booking value</span>
                    <span class="stat-value">{{ Display::money($report['gross_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Card payments</span>
                    <span class="stat-value">{{ Display::money($report['card_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Offline payments</span>
                    <span class="stat-value">{{ Display::money($report['offline_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Refunds</span>
                    <span class="stat-value">{{ Display::money($report['refunds_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Taxes</span>
                    <span class="stat-value">{{ Display::money($report['tax_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Booking fees charged</span>
                    <span class="stat-value">{{ Display::money($report['fee_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Booking fees refunded</span>
                    <span class="stat-value">{{ Display::money($report['fee_refunded_cents']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Cancellation rate</span>
                    <span class="stat-value">{{ Display::basisPoints($report['cancellation_bps']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">No-show rate</span>
                    <span class="stat-value">{{ Display::basisPoints($report['no_show_bps']) }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">New customers</span>
                    <span class="stat-value">{{ $report['new_customers'] }}</span>
                </span>
            </div>
            <div class="stat-card compact">
                <span class="stat-body">
                    <span class="stat-title">Repeat customers</span>
                    <span class="stat-value">{{ $report['repeat_customers'] }}</span>
                </span>
            </div>
        </div>

        <section class="panel">
            <div class="panel-head"><h2>Revenue by service</h2></div>
            @if ($report['revenue_by_service'] === [])
                <p class="muted">No confirmed or no-show bookings in this range.</p>
            @else
                <ul class="report-list">
                    @foreach ($report['revenue_by_service'] as $row)
                        <li data-service="{{ $row['service'] }}" data-service-gross="{{ $row['gross_cents'] }}">
                            <strong>{{ $row['service'] }}</strong>
                            @if ($row['location'] !== '')
                                <span class="muted">{{ $row['location'] }}</span>
                            @endif
                            <span>{{ $row['bookings'] }} {{ $row['bookings'] === 1 ? 'booking' : 'bookings' }}</span>
                            <span>{{ Display::money($row['gross_cents']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel top-gap">
            <div class="panel-head"><h2>Revenue by date</h2></div>
            @if ($report['revenue_by_date'] === [])
                <p class="muted">No confirmed or no-show bookings in this range.</p>
            @else
                <ul class="report-list">
                    @foreach ($report['revenue_by_date'] as $row)
                        <li data-date="{{ $row['date'] }}" data-date-gross="{{ $row['gross_cents'] }}">
                            <strong>{{ $row['date'] }}</strong>
                            <span>{{ $row['bookings'] }} {{ $row['bookings'] === 1 ? 'booking' : 'bookings' }}</span>
                            <span>{{ Display::money($row['gross_cents']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel top-gap">
            <div class="panel-head"><h2>Where bookings came from</h2></div>
            @if ($report['attribution'] === [])
                <p class="muted">No confirmed or no-show bookings in this range.</p>
            @else
                <ul class="report-list">
                    @foreach ($report['attribution'] as $row)
                        <li data-channel="{{ $row['channel'] }}" data-channel-bookings="{{ $row['bookings'] }}">
                            <strong>{{ $this->channelLabel($row['channel']) }}</strong>
                            <span>{{ $row['bookings'] }} {{ $row['bookings'] === 1 ? 'booking' : 'bookings' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel top-gap">
            <div class="panel-head"><h2>Tour capacity and attendance</h2></div>
            <p class="muted">Each scheduled departure in this range. Booked seats are confirmed and no-show parties. Attended seats are the confirmed parties. A held or cancelled booking is not a seat.</p>
            @if ($report['tours'] === [])
                <p class="muted">No tour departures in this range.</p>
            @else
                <ul class="report-list">
                    @foreach ($report['tours'] as $row)
                        <li
                            data-tour="{{ $row['departure_id'] }}"
                            data-tour-service="{{ $row['service'] }}"
                            data-tour-location="{{ $row['location'] }}"
                            data-tour-starts="{{ $row['starts_at'] }}"
                            data-tour-capacity="{{ $row['capacity'] }}"
                            data-tour-booked="{{ $row['seats_booked'] }}"
                            data-tour-attended="{{ $row['seats_attended'] }}"
                            data-tour-no-show="{{ $row['no_show_seats'] }}"
                            data-tour-fill="{{ $row['fill_rate_bps'] ?? '' }}"
                            data-tour-attendance="{{ $row['attendance_rate_bps'] ?? '' }}"
                        >
                            <strong>{{ $row['service'] }}</strong>
                            <span class="muted">{{ $row['location'] }}</span>
                            <span class="when" data-when>{{ $row['when'] }}</span>
                            <span>{{ $row['seats_booked'] }} of {{ $row['capacity'] }} booked</span>
                            <span>{{ $row['seats_attended'] }} attended</span>
                            <span>{{ $row['no_show_seats'] }} no-show</span>
                            <span>Fill {{ Display::basisPoints($row['fill_rate_bps']) }}</span>
                            <span>Attendance {{ Display::basisPoints($row['attendance_rate_bps']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel top-gap">
            <div class="panel-head"><h2>From the daily summary</h2></div>
            @if (! $report['summary_ready'])
                <div class="notice">
                    <h2>Daily summaries have not been built for this range yet.</h2>
                    <p>Utilization, conversion, average order value, and revenue per available {{ $industry->resourceHour() }} show up after that job runs. They are not calculated from scratch on this page.</p>
                </div>
            @else
                <div class="stat-grid">
                    <div class="stat-card compact">
                        <span class="stat-body">
                            <span class="stat-title">Utilization</span>
                            @if ($report['utilization_bps'] === null)
                                <span class="stat-value">Not enough hours</span>
                            @else
                                <span class="stat-value">{{ Display::basisPoints($report['utilization_bps']) }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="stat-card compact">
                        <span class="stat-body">
                            <span class="stat-title">Conversion</span>
                            <span class="stat-value">{{ Display::basisPoints($report['conversion_bps']) }}</span>
                        </span>
                    </div>
                    <div class="stat-card compact">
                        <span class="stat-body">
                            <span class="stat-title">Average order value</span>
                            @if ($report['average_order_cents'] === null)
                                <span class="stat-value">—</span>
                            @else
                                <span class="stat-value">{{ Display::money($report['average_order_cents']) }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="stat-card compact">
                        <span class="stat-body">
                            <span class="stat-title">Revenue per available {{ $industry->resourceHour() }}</span>
                            @if ($report['revenue_per_resource_hour_cents'] === null)
                                <span class="stat-value">Not enough hours</span>
                            @else
                                <span class="stat-value">{{ Display::money($report['revenue_per_resource_hour_cents']) }}</span>
                            @endif
                        </span>
                    </div>
                </div>
            @endif
        </section>
    </div>
</div>
