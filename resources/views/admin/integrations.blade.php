@extends('admin.layout')

@section('title', 'Integrations')

@section('content')
    <style>
        .retry-form { display: inline; background: transparent; border: 0; padding: 0; }
        .stopped-send { min-width: 0; }
        @media (max-width: 390px) {
            .retry-form, .retry-form button, .stopped-send { display: block; width: 100%; max-width: 100%; }
        }
    </style>
    <h1>Integrations</h1>
    <p class="muted"><a href="{{ route('staff.home') }}">Today</a></p>
    <p>GoHighLevel is not connected. These events are stored here and are not sent. Email is not sent either.</p>
    <div class="stats">
        <div class="stat"><strong>{{ $summary['queued'] }}</strong> Queued</div>
        <div class="stat"><strong>{{ $summary['held'] }}</strong> Held</div>
        <div class="stat"><strong>{{ $summary['notifications_skipped'] }}</strong> Emails skipped</div>
    </div>
    <h2>Outbox</h2>
    @if ($summary['events'] === [])
        <p>No integration events.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Kind</th><th>Status</th><th>Attempts</th><th>Last error</th></tr>
                </thead>
                <tbody>
                    @foreach ($summary['events'] as $event)
                        <tr>
                            <td>{{ $event['kind'] }}</td>
                            <td>{{ $event['status'] }}</td>
                            <td>{{ $event['attempts'] }}</td>
                            <td>{{ $event['last_error'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <h2>Stopped sends</h2>
    <p class="muted">A contact sync or a customer message that stopped after five tries can be sent again. The booking and its price stay as they are.</p>
    @if (session('status'))
        <p class="card">{{ session('status') }}</p>
    @endif
    @if (session('error'))
        <p class="error">{{ session('error') }}</p>
    @endif
    @if (($summary['stopped'] ?? []) === [])
        <p>No stopped sends.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Kind</th><th>Tries</th><th>Error</th>@if ($canRetry)<th></th>@endif</tr>
                </thead>
                <tbody>
                    @foreach ($summary['stopped'] as $stopped)
                        <tr class="stopped-send">
                            <td>{{ $stopped['kind'] }}</td>
                            <td>{{ $stopped['attempt'] }}</td>
                            <td>{{ $stopped['error'] }}</td>
                            @if ($canRetry)
                                <td>
                                    <form class="retry-form" method="post" action="{{ $stopped['type'] === 'message' ? route('admin.integrations.message-retry', $stopped['id']) : route('admin.integrations.sync-retry', $stopped['id']) }}">
                                        @csrf
                                        <button type="submit">Try again</button>
                                    </form>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
    <h2>Attempts</h2>
    <p class="muted">Each try is kept. Deferred means the outbox is still waiting. Held means it stopped after five tries. Payloads, response bodies, and secrets are not stored here.</p>
    @if (($summary['logs'] ?? []) === [])
        <p>No attempts yet.</p>
    @else
        <div class="table-wrap">
            <table>
                <thead>
                    <tr><th>Channel</th><th>Kind</th><th>Try</th><th>Status</th><th>HTTP</th><th>Error</th></tr>
                </thead>
                <tbody>
                    @foreach ($summary['logs'] as $log)
                        <tr>
                            <td>{{ $log['channel_label'] }}</td>
                            <td>{{ $log['kind'] }}</td>
                            <td>{{ $log['attempt'] }}</td>
                            <td>{{ $log['status'] }}</td>
                            <td>{{ $log['http_status'] ?? '' }}</td>
                            <td>{{ $log['error'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endsection
