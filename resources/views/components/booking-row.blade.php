@props(['booking', 'customer' => null, 'zone' => null, 'showDate' => false, 'withZone' => false])
@php
    use App\Support\Display;
    use App\Support\DisplayZone;
    $zone = DisplayZone::resolve($zone ?? ($booking->location->timezone ?? null));
    $status = $booking->status->value;
    $who = $customer?->name ?: ($customer?->email ?: 'Walk-in');
    $showZone = $withZone === true;
@endphp
<li class="rail-item status-{{ $status }}{{ $showZone ? ' with-zone' : '' }}" data-booking="{{ $booking->id }}">
    <time class="rail-time" datetime="{{ $booking->starts_at->toIso8601String() }}">
        @if ($showZone)
            <span class="home-when">{{ Display::rangeWithZone($booking->starts_at, $booking->ends_at, $zone) }}</span>
        @else
            @if ($showDate)<span class="rail-date">{{ Display::date($booking->starts_at, $zone) }}</span>@endif
            {{ Display::time($booking->starts_at, $zone) }}
        @endif
    </time>
    <a class="rail-body" href="/staff/bookings/{{ $booking->id }}" wire:navigate>
        <span class="rail-title">{{ $booking->service?->name ?? 'Booking' }}</span>
        <span class="rail-meta">
            {{ $who }}, {{ $booking->participants }} {{ $booking->participants === 1 ? 'person' : 'people' }}, until {{ $showZone ? Display::clockWithZone($booking->ends_at, $zone) : Display::time($booking->ends_at, $zone) }}
        </span>
    </a>
    <span class="badge badge-{{ $status }}">{{ Display::status($status) }}</span>
</li>
