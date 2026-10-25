@php
    use App\Support\Display;
    $status = $booking->status->value;
    $isRental = $booking->service?->kind?->value === 'rental';
    $open = in_array($status, ['held', 'pending_payment', 'confirmed'], true);
    $currency = $snapshot?->currency ?? 'USD';
    $p = $snapshot?->payload ?? [];
    $operatorFees = is_array($p['operator_fees'] ?? null) ? $p['operator_fees'] : [];
    $operatorCents = is_numeric($p['mandatory_operator_fees_cents'] ?? null) ? (int) $p['mandatory_operator_fees_cents'] : 0;
@endphp
<div class="page">
    <p class="crumb"><a href="/staff/bookings" wire:navigate>Bookings</a></p>
    <header class="page-head">
        <div>
            <h1>{{ $booking->service?->name ?? 'Booking' }}</h1>
            <p class="lede">{{ Display::range($booking->starts_at, $booking->ends_at, $zone) }}</p>
        </div>
        <span class="badge large badge-{{ $status }}">{{ Display::status($status) }}</span>
    </header>

    <div aria-live="polite">
        @if ($flash)
            <p class="flash ok">{{ $flash }}</p>
        @endif
        @if ($problem)
            <p class="flash bad" role="alert">{{ $problem }}</p>
        @endif
    </div>

    @if ($status === 'held' && $holdExpires)
        <p class="notice attention">
            Held until {{ Display::time($holdExpires, $zone) }}. Take payment or confirm before then, or {{ $industry->holdExpiry() }}.
        </p>
    @endif

    <div class="split">
        <div class="stack">
            <section class="panel">
                <h2>Details</h2>
                <dl class="facts">
                    <div><dt>Customer</dt><dd>{{ $customer?->name ?: 'Walk-in' }}@if($customer?->email)<br><a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>@endif</dd></div>
                    <div><dt>Party</dt><dd>{{ $booking->participants }} {{ $booking->participants === 1 ? 'person' : 'people' }}</dd></div>
                    <div><dt>Location</dt><dd>{{ $booking->location?->name }}</dd></div>
                    <div><dt>Payment</dt><dd>{{ Display::status($booking->payment_status->value) }}</dd></div>
                    <div><dt>On the water</dt><dd>{{ Display::status($booking->operational_status->value) }}</dd></div>
                    <div>
                        <dt>Assigned</dt>
                        <dd>
                            @forelse ($booking->allocations->concat($booking->holds) as $row)
                                <span class="tag">{{ $row->asset_id ? ($assets[$row->asset_id] ?? 'Asset') : (($pools[$row->pool_id] ?? 'Pool').' × '.$row->quantity) }}</span>
                            @empty
                                <span class="muted">Nothing assigned</span>
                            @endforelse
                        </dd>
                    </div>
                </dl>
            </section>

            <section class="panel">
                <h2>Price</h2>
                @if ($snapshot)
                    @include('disclosures.checkout-summary', [
                        'subtotalCents' => max(0, (int) ($p['basis_cents'] ?? 0) - $operatorCents),
                        'operatorFees' => $operatorFees,
                        'bookingFeeCents' => $p['customer_fee_line_cents'] ?? null,
                        'taxCents' => (int) ($p['tax_cents'] ?? 0),
                        'totalCents' => (int) ($p['customer_total_cents'] ?? 0),
                        'currency' => $currency,
                        'refundTerms' => $disclosure,
                    ])
                    @if (($p['charge_cents'] ?? 0) !== ($p['customer_total_cents'] ?? 0))
                        <p class="muted">Due now: {{ Display::money((int) $p['charge_cents'], $currency) }}{{ ! empty($p['deposit_was_raised']) ? ' (deposit raised to cover the booking fee)' : '' }}.</p>
                    @endif
                @elseif ($canManage && $open)
                    <p class="muted">Price this booking before taking payment.</p>
                    <form wire:submit="price" class="form-row">
                        <label class="field compact">
                            <span>Sales tax (%)</span>
                            <input type="number" min="0" max="100" step="0.01" wire:model="taxPercent">
                        </label>
                        <label class="field compact">
                            <span>Deposit (%)</span>
                            <input type="number" min="1" max="100" wire:model="depositPercent">
                        </label>
                        <label class="field compact">
                            <span>Promo code</span>
                            <input type="text" wire:model="promoCode" maxlength="40">
                        </label>
                        <button type="submit" class="button primary">Calculate price</button>
                    </form>
                    @error('taxPercent')<p class="error">{{ $message }}</p>@enderror
                @else
                    <p class="muted">Not priced.</p>
                @endif

                @if ($payments->isNotEmpty())
                    <h3>Payments</h3>
                    <ul class="plain-list">
                        @foreach ($payments as $payment)
                            <li>
                                {{ Display::money($payment->amount_cents, $currency) }}
                                <span class="muted">{{ $payment->method === 'offline' ? 'Paid offline' : 'Card' }}, {{ Display::status($payment->status) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($paymentUrl)
                    <p class="payment-link">
                        <a class="button primary" href="{{ $paymentUrl }}" target="_blank" rel="noopener">Open card payment</a>
                        <input type="text" readonly value="{{ $paymentUrl }}" aria-label="Payment link" onclick="this.select()">
                    </p>
                @endif
            </section>

            @if ($history->isNotEmpty())
                <section class="panel">
                    <h2>History</h2>
                    <ol class="history">
                        @foreach ($history as $entry)
                            <li><span>{{ Display::status(str_replace('booking.', '', $entry->action)) }}</span> <time class="muted">{{ Display::dateTime($entry->created_at, $zone) }}</time></li>
                        @endforeach
                    </ol>
                </section>
            @endif
        </div>

        @if ($canManage)
            <aside class="stack">
                <section class="panel">
                    <h2>Take payment</h2>
                    @if (! $snapshot)
                        <p class="muted">Calculate the price first.</p>
                    @elseif (in_array($booking->payment_status->value, ['paid', 'offline_paid'], true))
                        <p class="muted">Paid.</p>
                    @elseif ($open)
                        <div class="actions">
                            <button type="button" class="button" wire:click="recordOffline" wire:confirm="Record that the customer paid by cash, check, or another terminal? No booking fee applies.">Paid offline</button>
                            @if ($canCharge)
                                <button type="button" class="button primary" wire:click="createPaymentLink">Card payment link</button>
                            @else
                                <p class="muted small">Card payments turn on once Stripe is connected in Settings.</p>
                            @endif
                        </div>
                    @endif
                    @if ($status === 'held')
                        <button type="button" class="button" wire:click="confirm">Confirm without payment</button>
                    @endif
                </section>

                @if ($open)
                    <section class="panel">
                        <h2>On the water</h2>
                        <div class="actions">
                            @if ($isRental)
                                <button type="button" class="button" wire:click="checkOut">Check out gear</button>
                                <button type="button" class="button" wire:click="markReturned">Mark returned</button>
                            @endif
                            <label class="field compact">
                                <span>Status</span>
                                <select wire:change="setOperational($event.target.value)">
                                    @foreach ($operationalStatuses as $op)
                                        <option value="{{ $op->value }}" @selected($booking->operational_status === $op)>{{ Display::status($op->value) }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </section>

                    <section class="panel">
                        <h2>Change time</h2>
                        <form wire:submit="reschedule" class="stack">
                            <label class="field"><span>Start</span><input type="datetime-local" wire:model="rescheduleStart"></label>
                            <label class="field"><span>End</span><input type="datetime-local" wire:model="rescheduleEnd"></label>
                            @error('rescheduleEnd')<p class="error">{{ $message }}</p>@enderror
                            <button type="submit" class="button">Reschedule</button>
                        </form>
                        @if ($isRental)
                            <form wire:submit="extend" class="stack top-gap">
                                <label class="field"><span>New return time</span><input type="datetime-local" wire:model="extendEnd"></label>
                                <button type="submit" class="button">Extend rental</button>
                            </form>
                        @endif
                    </section>

                    <section class="panel">
                        <h2>Close out</h2>
                        <div class="actions">
                            <button type="button" class="button" wire:click="markNoShow" wire:confirm="{{ $industry->noShowConfirm() }}">No-show</button>
                            <button type="button" class="button danger" wire:click="cancelBooking" wire:confirm="{{ $industry->cancelConfirm() }}">Cancel booking</button>
                        </div>
                    </section>
                @endif
            </aside>
        @endif
    </div>
</div>
