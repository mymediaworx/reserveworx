@extends('widget.layout')
@section('title', $tenantName.' bookings')

@section('content')
    <h1>{{ $tenantName }}</h1>
    <p>Choose a service. Prices shown here are the total, including the booking fee when the operator charges one.</p>
    <p><a href="{{ url('/book/'.$token.'/lookup') }}">Look up a booking</a></p>
    @forelse ($services as $row)
        <article class="card">
            <h2>{{ $row['service']->name }}</h2>
            @if ($row['total_cents'] !== null)
                <p class="price">{{ \App\Domain\Fees\Disclosures\Money::format($row['total_cents'], $row['currency']) }}
                    @if ($row['fee_included'])
                        <span>Booking fee included</span>
                    @endif
                </p>
            @else
                <p>The total is shown before payment.</p>
            @endif
            <p><a href="{{ url('/book/'.$token.'/services/'.$row['service']->id) }}">Check times</a></p>
        </article>
    @empty
        <p>No services are available to book.</p>
    @endforelse
@endsection
