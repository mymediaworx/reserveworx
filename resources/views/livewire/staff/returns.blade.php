@php
    use App\Models\Booking;
    use App\Enums\LateFeeDecision;
    use App\Enums\ReturnCondition;
    use App\Support\Display;
    use App\Support\LateFee;
    use App\Support\Lateness;
    use Carbon\CarbonImmutable;
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
        .fee-choice { display: grid; gap: 0.35rem; margin: 0; padding: 0; border: 0; }
        .fee-choice label { display: flex; gap: 0.5rem; align-items: center; }
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
                    $zone = \App\Support\DisplayZone::forLocation($booking?->location);
                    $due = $checkout->due_at;
                    $overdue = $due !== null && Lateness::overdue($due, $now);
                    $minutes = $due !== null ? Lateness::minutes($due, $now) : 0;
                    $resource = $checkout->asset?->name ?? $checkout->pool?->name ?? 'Resource';
                    $customer = $booking?->customer;
                    $entered = $returnedAt[$checkout->id] ?? '';
                    $feeMinutes = 0;
                    $feeCents = 0;
                    $pastDue = false;
                    if ($due !== null && is_string($entered) && $entered !== '') {
                        $parsedReturn = CarbonImmutable::createFromFormat('Y-m-d\TH:i', $entered, $zone);
                        if ($parsedReturn instanceof CarbonImmutable) {
                            $pastDue = Lateness::overdue($due, $parsedReturn);
                            $feeMinutes = LateFee::billableMinutes($due, $parsedReturn, $graceMinutes);
                            $feeCents = LateFee::amountCents($feeMinutes, $lateFeeRate);
                        }
                    }
                @endphp
                <li @class(['return-card', 'is-late' => $overdue]) data-checkout="{{ $checkout->id }}" data-overdue="{{ $overdue ? 'yes' : 'no' }}" data-minutes="{{ $minutes }}" wire:key="return-{{ $checkout->id }}">
                    <strong>{{ $resource }}</strong>
                    <span>{{ $booking?->service?->name }}</span>
                    <span>{{ $customer?->name ?: ($customer?->email ?: 'Walk-in') }}</span>
                    @if ($booking instanceof Booking)
                        <a href="/staff/bookings/{{ $booking->id }}">Open booking</a>
                    @endif
                    <span data-due>Due {{ $due ? Display::dateTimeWithZone($due, $zone) : '—' }}</span>
                    @if ($overdue && $due)
                        <span class="late">{{ Lateness::label($due, $now) }}</span>
                    @endif
                    <div class="return-form">
                        <label class="field">
                            <span>Return time</span>
                            <input type="datetime-local" wire:model.live="returnedAt.{{ $checkout->id }}" required>
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
                        @if ($feeCents > 0)
                            <p class="late" data-late-fee="{{ $feeCents }}" data-late-minutes="{{ $feeMinutes }}">Late fee {{ Display::money($feeCents, $currency) }} for {{ $feeMinutes }} {{ $feeMinutes === 1 ? 'minute' : 'minutes' }}</p>
                            <fieldset class="fee-choice">
                                <legend class="sr-only">Late fee</legend>
                                <label>
                                    <input type="radio" wire:model.live="lateFeeAction.{{ $checkout->id }}" value="{{ LateFeeDecision::Applied->value }}">
                                    <span>Apply fee</span>
                                </label>
                                <label>
                                    <input type="radio" wire:model.live="lateFeeAction.{{ $checkout->id }}" value="{{ LateFeeDecision::Waived->value }}">
                                    <span>Waive fee</span>
                                </label>
                            </fieldset>
                            @error('lateFeeAction.'.$checkout->id)<p class="error">{{ $message }}</p>@enderror
                            @if (($lateFeeAction[$checkout->id] ?? '') === LateFeeDecision::Waived->value)
                                <label class="field">
                                    <span>Reason for waiving</span>
                                    <textarea wire:model="lateFeeReason.{{ $checkout->id }}" rows="2" maxlength="500" required></textarea>
                                </label>
                                @error('lateFeeReason.'.$checkout->id)<p class="error">{{ $message }}</p>@enderror
                            @endif
                        @elseif ($graceMinutes > 0 && $pastDue)
                            <p data-grace="yes">Inside the grace period. No late fee.</p>
                        @endif
                        <button type="button" class="button primary" wire:click="recordReturn('{{ $checkout->id }}')" wire:loading.attr="disabled" wire:target="recordReturn">Return</button>
                        @if ($booking instanceof Booking && is_string($checkout->asset_id) && $checkout->asset_id !== '')
                            <div class="damage-report">
                                <h3>Damage report</h3>
                                <livewire:staff.file-incident :booking-id="$booking->id" :fixed-asset-id="$checkout->asset_id" :key="'return-incident-'.$checkout->id" />
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
