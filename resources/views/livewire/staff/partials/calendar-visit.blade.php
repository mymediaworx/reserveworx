@php
    use App\Models\Location;
    use App\Support\Display;
    use App\Support\DisplayZone;
    $zone = $booking->location instanceof Location
        ? DisplayZone::forLocation($booking->location)
        : DisplayZone::resolve($zone ?? null);
    $status = $booking->status->value;
    $who = $customer?->name ?: ($customer?->email ?: 'Walk-in');
    $people = $booking->participants === 1 ? 'person' : 'people';
@endphp
<li class="rail-item status-{{ $status }}" data-booking="{{ $booking->id }}">
    <time class="rail-time" datetime="{{ $booking->starts_at->toIso8601String() }}">{{ Display::rangeWithZone($booking->starts_at, $booking->ends_at, $zone) }}</time>
    <a class="rail-body" href="/staff/bookings/{{ $booking->id }}" wire:navigate>
        <span class="rail-title">{{ $booking->service?->name ?? 'Booking' }}</span>
        <span class="rail-meta">{{ $who }}, {{ $booking->participants }} {{ $people }}</span>
    </a>
    <span class="badge badge-{{ $status }}">{{ Display::status($status) }}</span>
</li>
