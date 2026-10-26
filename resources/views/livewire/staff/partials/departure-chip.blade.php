@props(['row', 'zone'])
@php
    use App\Support\Display;
    $departure = $row['departure'];
    $below = $row['belowMinimum'];
    $minimum = $row['minimum'];
    $participants = $row['participants'];
    $date = \Carbon\CarbonImmutable::instance($departure->starts_at)->timezone($zone)->toDateString();
@endphp
<a
    class="departure-chip{{ $below ? ' is-below-minimum' : '' }}"
    href="/staff/manifest?date={{ $date }}&amp;location={{ $departure->location_id }}"
    wire:navigate
    data-departure="{{ $departure->id }}"
    @if ($below) data-below-minimum="{{ $departure->id }}" @endif
>
    <strong>{{ Display::time($departure->starts_at, $zone) }}</strong>
    {{ $departure->service?->name ?? 'Tour' }}
    <span class="muted">
        @if ($below && is_int($minimum))
            {{ $participants }} of {{ $minimum }}
        @else
            {{ $participants }}{{ $departure->seats ? '/'.$departure->seats : '' }}
        @endif
    </span>
    @if ($below)
        <span class="badge badge-below-minimum">Below minimum</span>
    @endif
</a>
