@php use App\Support\Display; @endphp
@if ($windows->isEmpty())
    <p class="empty">{{ $emptyText }}</p>
@else
    <ul class="window-list">
        @foreach ($windows as $window)
            @php
                $item = $window->asset ?? $window->pool;
                $zone = \App\Support\DisplayZone::forLocation($item?->location);
                $hits = $affected[$window->id] ?? collect();
            @endphp
            <li @class(['window-card', 'is-live' => $live]) wire:key="window-{{ $window->id }}">
                <div class="meta">
                    <strong>{{ $item?->name }}</strong>
                    <span @class(['badge', 'badge-held' => $window->kind->value === 'maintenance', 'badge-pending' => $window->kind->value === 'block'])>{{ $window->kind->label() }}</span>
                </div>
                <span>{{ $window->reason }}</span>
                <span class="muted">{{ Display::range($window->starts_at, $window->ends_at, $zone) }}</span>
                @if ($canEnd ?? false)
                    <button type="button" class="button small" wire:click="endWindow('{{ $window->id }}')" wire:confirm="End this now? It can be booked again after the current time. Bookings stay where they are.">End now</button>
                @endif
                @if ($hits->isNotEmpty())
                    <div class="affected">
                        <p class="muted small">{{ $hits->count() }} future {{ $hits->count() === 1 ? 'booking overlaps' : 'bookings overlap' }}. {{ $hits->count() === 1 ? 'It stays' : 'They stay' }} booked until you move or contact the guest.</p>
                        <ul class="overlap-list">
                            @foreach ($hits as $booking)
                                <li data-affected-booking="{{ $booking->id }}">
                                    <a href="/staff/bookings/{{ $booking->id }}" wire:navigate>
                                        {{ $booking->customer?->name ?: 'Booking' }} · {{ Display::dateTime($booking->starts_at, $zone) }}
                                    </a>
                                    @if ($booking->attention_reason)
                                        <span class="badge badge-payment">Flagged</span>
                                    @endif
                                    @if (in_array($booking->status->value, ['held', 'pending_payment'], true))
                                        <span class="badge badge-{{ $booking->status->value }}">{{ Display::status($booking->status->value) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @else
                    <p class="muted small">No future bookings overlap.</p>
                @endif
            </li>
        @endforeach
    </ul>
@endif
