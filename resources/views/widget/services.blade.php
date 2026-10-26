@extends('widget.layout')
@php
    $publicName = (isset($workspaceBranding) && $workspaceBranding->displayName !== '')
        ? $workspaceBranding->displayName
        : $tenantName;
@endphp
@section('title', $publicName.' bookings')

@section('hide_bar', '1')

@section('content')
    <div class="w-hero">
        <span class="w-avatar w-avatar-lg">
            @if ($workspaceBranding->logoUrl ?? null)
                <img src="{{ $workspaceBranding->logoUrl }}" alt="">
            @else
                @php $parts = preg_split('/\s+/', trim($publicName)) ?: []; @endphp
                <span aria-hidden="true">{{ strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1)) }}</span>
            @endif
        </span>
        <h1>{{ $publicName }}</h1>
    </div>
    <p>Choose a service. Prices shown here are the total, including the booking fee when the operator charges one.</p>
    <p><a href="{{ url('/book/'.$token.'/lookup') }}">Look up a booking</a></p>
    <p><a href="{{ url('/account') }}" target="_blank" rel="noopener">Open an account</a> <span class="optional">Booking does not require one.</span></p>
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
