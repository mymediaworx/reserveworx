@props(['booking', 'customer' => null, 'zone' => 'UTC', 'showDate' => false])
@php
    use App\Support\Display;
    $status = $booking->status->value;
    $who = $customer?->name ?: ($customer?->email ?: 'Walk-in');
@endphp
<li class="rail-item status-{{ $status }}" data-booking="{{ $booking->id }}">
    <time class="rail-time" datetime="{{ $booking->starts_at->toIso8601String() }}">
        @if ($showDate)<span class="rail-date">{{ Display::date($booking->starts_at, $zone) }}</span>@endif
        {{ Display::time($booking->starts_at, $zone) }}
    </time>
    <a class="rail-body" href="/staff/bookings/{{ $booking->id }}" wire:navigate>
        <span class="rail-title">{{ $booking->service?->name ?? 'Booking' }}</span>
        <span class="rail-meta">
            {{ $who }}, {{ $booking->participants }} {{ $booking->participants === 1 ? 'person' : 'people' }}, until {{ Display::time($booking->ends_at, $zone) }}
        </span>
    </a>
    <span class="badge badge-{{ $status }}">{{ Display::status($status) }}</span>
</li>
