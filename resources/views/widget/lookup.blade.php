@extends('widget.layout')
@section('title', 'Look up a booking')

@section('content')
    <p><a href="{{ url('/book/'.$token) }}">All services</a></p>
    <h1>Look up a booking</h1>
    @if ($error)
        <p class="error" role="alert">{{ $error }}</p>
    @endif
    <form method="post" action="{{ url('/book/'.$token.'/lookup') }}">
        <label>Booking reference <span class="optional">(the 8 characters on your confirmation, or the full reference)</span>
            <input name="reference" type="text" required maxlength="40" autocomplete="off">
        </label>
        <label>Email
            <input name="email" type="email" required maxlength="255" autocomplete="email">
        </label>
        <button type="submit">Show booking</button>
    </form>
@endsection
