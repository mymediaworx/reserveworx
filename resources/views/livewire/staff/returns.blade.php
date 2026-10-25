@php
    use App\Models\Booking;
    use App\Enums\ReturnCondition;
    use App\Support\Display;
    use App\Support\Lateness;
@endphp
<div class="page returns-page">
    <style>
        .returns-page { min-width: 0; max-width: 46rem; }
        .return-list { display: grid; gap: 0.75rem; margin: 0; padding: 0; list-style: none; }
        .return-card {
            display: grid;
            gap: 0.35rem;
            min-width: 0;
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            padding: 0.9rem 1rem;
            box-shadow: var(--shadow);
        }
        .return-card.is-late { background: var(--danger-soft); border-color: #FECACA; }
        .return-card .late { color: var(--danger); font-weight: 600; }
        .return-card .return-form { display: grid; gap: 0.75rem; margin-top: 0.35rem; }
        .return-card .return-form .button { width: 100%; }
        @media (max-width: 390px) {
            .returns-page { min-width: 0; }
            .returns-page .return-card,
            .returns-page .return-card * { overflow-wrap: anywhere; min-width: 0; max-width: 100%; }
            .returns-page input,
            .returns-page select,
            .returns-page textarea,
            .returns-page .button { width: 100%; max-width: 100%; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Returns</h1>
            <p class="lede">Due back today, including anything still out past its time.</p>
        </div>
    </header>

    @if ($flash)
        <p class="flash ok" role="status">{{ $flash }}</p>
    @endif
    @if ($problem)
        <p class="flash bad" role="alert">{{ $problem }}</p>
    @endif

    <form class="filters" wire:submit.prevent>
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

    @if ($checkouts->isEmpty())
        <p class="empty">Nothing is due back right now.</p>
    @else
        <ul class="return-list">
            @foreach ($checkouts as $checkout)
                @php
                    $booking = $checkout->booking;
                    $zoneName = $booking?->location?->timezone;
                    $zone = is_string($zoneName) && in_array($zoneName, timezone_identifiers_list(), true) ? $zoneName : 'UTC';
                    $due = $checkout->due_at;
                    $overdue = $due !== null && Lateness::overdue($due, $now);
                    $minutes = $due !== null ? Lateness::minutes($due, $now) : 0;
                    $resource = $checkout->asset?->name ?? $checkout->pool?->name ?? 'Resource';
                    $customer = $booking?->customer;
                @endphp
                <li @class(['return-card', 'is-late' => $overdue]) data-checkout="{{ $checkout->id }}" data-overdue="{{ $overdue ? 'yes' : 'no' }}" data-minutes="{{ $minutes }}" wire:key="return-{{ $checkout->id }}">
                    <strong>{{ $resource }}</strong>
                    <span>{{ $booking?->service?->name }}</span>
                    <span>{{ $customer?->name ?: ($customer?->email ?: 'Walk-in') }}</span>
                    @if ($booking instanceof Booking)
                        <a href="/staff/bookings/{{ $booking->id }}">Open booking</a>
                    @endif
                    <span>Due {{ $due ? Display::dateTime($due, $zone) : '—' }}</span>
                    @if ($overdue && $due)
                        <span class="late">{{ Lateness::label($due, $now) }}</span>
                    @endif
                    <div class="return-form">
                        <label class="field">
                            <span>Return time</span>
                            <input type="datetime-local" wire:model="returnedAt.{{ $checkout->id }}" required>
                        </label>
                        @error('returnedAt.'.$checkout->id)<p class="error">{{ $message }}</p>@enderror
                        <label class="field">
                            <span>Condition</span>
                            <select wire:model="condition.{{ $checkout->id }}">
                                <option value="{{ ReturnCondition::Good->value }}">Good</option>
                                <option value="{{ ReturnCondition::NeedsAttention->value }}">Needs attention</option>
                                <option value="{{ ReturnCondition::Damaged->value }}">Damaged</option>
                            </select>
                        </label>
                        @error('condition.'.$checkout->id)<p class="error">{{ $message }}</p>@enderror
                        <label class="field">
                            <span>Note</span>
                            <textarea wire:model="note.{{ $checkout->id }}" rows="2" maxlength="500" placeholder="Optional"></textarea>
                        </label>
                        @error('note.'.$checkout->id)<p class="error">{{ $message }}</p>@enderror
                        <button type="button" class="button primary" wire:click="recordReturn('{{ $checkout->id }}')" wire:loading.attr="disabled" wire:target="recordReturn">Return</button>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
