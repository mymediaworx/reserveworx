@php
    use App\Enums\IncidentSeverity;
    use App\Enums\IncidentStatus;
    use App\Models\Booking;
    use App\Support\Display;
@endphp
<div class="page incidents-page">
    <style>
        .incidents-page { min-width: 0; max-width: 46rem; }
        .incident-list { display: grid; gap: 0.75rem; margin: 0; padding: 0; list-style: none; }
        .incident-card {
            display: grid;
            gap: 0.35rem;
            min-width: 0;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 0.9rem 1rem;
            box-shadow: var(--shadow);
        }
        .incident-card .meta { display: flex; flex-wrap: wrap; gap: 0.35rem 0.6rem; align-items: center; }
        .incident-card.is-serious { border-color: #FECACA; }
        .incident-photos { display: flex; flex-wrap: wrap; gap: 0.4rem; }
        .incident-photos img { width: 5.5rem; height: 5.5rem; object-fit: cover; border-radius: 0.4rem; background: var(--line); }
        .incident-card .button { width: auto; }
        @media (max-width: 390px) {
            .incidents-page { min-width: 0; }
            .incidents-page .incident-card,
            .incidents-page .incident-card * { overflow-wrap: anywhere; min-width: 0; max-width: 100%; }
            .incidents-page .filters,
            .incidents-page .filters .field { width: 100%; max-width: 100%; }
            .incidents-page input,
            .incidents-page select,
            .incidents-page .button { width: 100%; max-width: 100%; }
            .incidents-page .incident-photos img { width: 4.5rem; height: 4.5rem; }
            .incidents-page .when { overflow-wrap: anywhere; max-width: 100%; }
        }
    </style>

    <header class="page-head">
        <div>
            <h1>Incidents</h1>
            <p class="lede">Damage and other reports filed from returns or a booking.</p>
        </div>
    </header>

    @if ($flash)
        <p class="flash ok" role="status">{{ $flash }}</p>
    @endif

    <form class="filters" wire:submit.prevent>
        <label class="field compact">
            <span>Status</span>
            <select wire:model.live="status">
                <option value="open">Open</option>
                <option value="resolved">Resolved</option>
                <option value="all">All</option>
            </select>
        </label>
        <label class="field compact">
            <span>Severity</span>
            <select wire:model.live="severity">
                <option value="">Any</option>
                @foreach (IncidentSeverity::cases() as $level)
                    <option value="{{ $level->value }}">{{ $level->label() }}</option>
                @endforeach
            </select>
        </label>
        @if ($locations->isNotEmpty())
            <label class="field compact">
                <span>Location</span>
                <select wire:model.live="location">
                    <option value="">All locations</option>
                    @foreach ($locations as $option)
                        <option value="{{ $option->id }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </form>

    @if ($incidents->isEmpty())
        <p class="empty">No reports match these filters.</p>
    @else
        <ul class="incident-list">
            @foreach ($incidents as $incident)
                @php
                    $booking = $incident->booking;
                    $zone = \App\Support\DisplayZone::forLocation($booking?->location);
                    $text = is_string($incident->notes) && $incident->notes !== '' ? $incident->notes : $incident->summary;
                @endphp
                <li @class(['incident-card', 'is-serious' => $incident->severity === IncidentSeverity::Serious]) data-incident="{{ $incident->id }}" data-severity="{{ $incident->severity?->value ?? '' }}" data-status="{{ $incident->status->value }}" @if (is_int($incident->damage_cents)) data-damage="{{ $incident->damage_cents }}" @endif wire:key="incident-{{ $incident->id }}">
                    <div class="meta">
                        <strong>{{ $incident->asset?->name ?? $incident->pool?->name ?? 'Boat' }}</strong>
                        <span class="badge">{{ $incident->severity?->label() ?? 'Recorded' }}</span>
                        <span class="badge">{{ $incident->status->label() }}</span>
                    </div>
                    @if ($booking instanceof Booking)
                        <span>{{ $booking->customer?->name ?: 'Walk-in' }} · {{ $booking->service?->name }}</span>
                        <a href="/staff/bookings/{{ $booking->id }}">Open booking</a>
                    @endif
                    <span>{{ $booking?->location?->name }}</span>
                    <span class="when">Reported {{ Display::dateTimeWithZone($incident->occurred_at, $zone) }}</span>
                    <p>{{ $text }}</p>
                    @if (is_int($incident->damage_cents) && $incident->damage_cents > 0)
                        <span>Damage {{ Display::money($incident->damage_cents, $currency) }} on the customer total</span>
                    @endif
                    @if ($incident->photos->isNotEmpty())
                        <div class="incident-photos">
                            @foreach ($incident->photos as $photo)
                                <a href="{{ route('staff.incidents.photo', ['incident' => $incident, 'photo' => $photo]) }}">
                                    <img src="{{ route('staff.incidents.photo', ['incident' => $incident, 'photo' => $photo]) }}" alt="Photo of the damage">
                                </a>
                            @endforeach
                        </div>
                    @endif
                    @if ($incident->maintenanceWindow)
                        @if ($canMaintain)
                            <a href="/staff/maintenance">On the Maintenance page</a>
                        @else
                            <span>On the Maintenance page</span>
                        @endif
                    @endif
                    @if ($canResolve && $incident->status === IncidentStatus::Open)
                        <button type="button" class="button" wire:click="resolve('{{ $incident->id }}')">Mark resolved</button>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
