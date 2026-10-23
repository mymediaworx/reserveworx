@extends('admin.layout')

@section('title', 'Today')

@section('content')
    <h1>Today</h1>
    <p class="muted"><a href="{{ route('admin.integrations') }}">Integrations</a> · <a href="{{ route('admin.report.export') }}">Download CSV</a></p>
    @if ($error)
        <p class="error">{{ $error }}</p>
    @endif
    @if (is_array($report))
        <p class="muted">{{ $report['date'] }} in {{ $report['timezone'] }}. Bookings use each location's own timezone. Fee totals are all time. This page reads live rows.</p>
        <div class="stats">
            <div class="stat"><strong>{{ count($report['bookings']) }}</strong> Bookings</div>
            <div class="stat"><strong>{{ count($report['departures']) }}</strong> Departures</div>
            <div class="stat"><strong>{{ $report['open_checkouts'] }}</strong> Checked out</div>
            <div class="stat"><strong>{{ $report['overdue_checkouts'] }}</strong> Overdue</div>
            <div class="stat"><strong>${{ number_format($report['payments']['stripe_cents'] / 100, 2) }}</strong> Card payments</div>
            <div class="stat"><strong>${{ number_format($report['payments']['offline_cents'] / 100, 2) }}</strong> Offline payments</div>
            <div class="stat"><strong>${{ number_format($report['fees']['net_cents'] / 100, 2) }}</strong> Net booking fees</div>
        </div>

        <h2>Bookings</h2>
        @if ($report['bookings'] === [])
            <p>No bookings on this date.</p>
        @else
            <table>
                <thead>
                    <tr><th>Time (UTC)</th><th>Service</th><th>Status</th><th>Party</th><th>Attention</th></tr>
                </thead>
                <tbody>
                    @foreach ($report['bookings'] as $booking)
                        <tr>
                            <td>{{ $booking['starts_at'] }}</td>
                            <td>{{ $booking['service'] }}</td>
                            <td>{{ $booking['status'] }}</td>
                            <td>{{ $booking['participants'] }}</td>
                            <td>{{ $booking['attention_reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @if ($report['bookings_omitted'] > 0)
                <p class="muted">{{ $report['bookings_omitted'] }} more bookings are not listed.</p>
            @endif
        @endif

        <h2>Departures</h2>
        @if ($report['departures'] === [])
            <p>No departures on this date.</p>
        @else
            <table>
                <thead>
                    <tr><th>Time (UTC)</th><th>Service</th><th>Seats</th><th>Headcount</th></tr>
                </thead>
                <tbody>
                    @foreach ($report['departures'] as $departure)
                        <tr>
                            <td>{{ $departure['starts_at'] }}</td>
                            <td>{{ $departure['service'] }}</td>
                            <td>{{ $departure['seats'] ?? 'Service maximum' }}</td>
                            <td>{{ $departure['headcount'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <h2>Booking fees</h2>
        <p>Charged ${{ number_format($report['fees']['charged_cents'] / 100, 2) }}. Refunded ${{ number_format($report['fees']['refunded_cents'] / 100, 2) }}.</p>
    @endif
    <form method="post" action="{{ route('admin.logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
@endsection
