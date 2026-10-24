@extends('widget.layout')
@section('title', 'Leave the waitlist')

@section('content')
    <div class="waitlist-page">
        <style>
            .waitlist-page, .waitlist-page * { min-width: 0; overflow-wrap: anywhere; }
            @media (max-width: 390px) {
                .waitlist-page form, .waitlist-page button { max-width: 100%; }
            }
        </style>
        @if ($entry->status->value === 'waiting')
            <h1>Leave the waitlist</h1>
            <p>You are waiting for {{ $serviceName }} on {{ $when }}.</p>
            <form method="post" action="{{ request()->fullUrl() }}">
                @csrf
                <button type="submit">Leave the waitlist</button>
            </form>
        @elseif ($entry->status->value === 'cancelled')
            <h1>You left the waitlist</h1>
            <p>You will not be offered {{ $serviceName }} on {{ $when }}.</p>
        @else
            <h1>This spot was already offered</h1>
            <p>{{ $serviceName }} on {{ $when }} is a booking now. Leaving the waitlist does not cancel that booking.</p>
        @endif
    </div>
@endsection
