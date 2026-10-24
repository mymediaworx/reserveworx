@extends('widget.layout')
@section('title', 'You are on the waitlist')

@section('content')
    <div class="waitlist-page">
        <style>
            .waitlist-page, .waitlist-page * { min-width: 0; overflow-wrap: anywhere; }
            @media (max-width: 390px) {
                .waitlist-page { max-width: 100%; }
            }
        </style>
        <h1>You are on the waitlist</h1>
        <p>{{ $serviceName }} on {{ $when }} for {{ $participants === 1 ? '1 person' : $participants.' people' }}.</p>
        @if ($already)
            <p>This request was already on the waitlist.</p>
        @elseif ($emailed)
            <p>We emailed {{ $email }} a link you can use to leave the waitlist.</p>
        @else
            <p>The shop has your name. Ask them if you need to leave the list.</p>
        @endif
        <p><a href="{{ url('/book/'.$token) }}">Back to services</a></p>
    </div>
@endsection
