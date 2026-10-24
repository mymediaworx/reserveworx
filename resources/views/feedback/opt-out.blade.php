@extends('widget.layout')

@section('title', 'Feedback emails')

@section('content')
    <h1>Feedback emails</h1>
    @if ($optedOut)
        <div class="card">
            <h2>You're opted out</h2>
            <p>We won't email you for feedback after a visit.</p>
        </div>
    @else
        <p>Stop feedback emails for {{ $serviceName }} and later visits. This does not cancel a booking.</p>
        <form method="POST" action="{{ request()->fullUrl() }}">
            @csrf
            <button type="submit">Stop feedback emails</button>
        </form>
    @endif
@endsection
