@php
    use App\Domain\Pricing\GroupPriceCopy;
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
            <p class="lede" data-when>{{ Display::rangeWithZone($booking->starts_at, $booking->ends_at, $zone) }}</p>
            <style>
                p[data-when] { max-width: 100%; overflow-wrap: anywhere; }
                @media (max-width: 390px) {
                    p[data-when] { max-width: 100%; min-width: 0; overflow-wrap: anywhere; }
                }
            </style>
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
    @include('livewire.staff.partials.undo-toast')

    @if ($status === 'payment_exception')
        <section class="notice attention" role="alert" data-needs-attention="payment_exception">
            <p><strong>Paid, but the time was no longer free.</strong> The customer's payment went through after their hold ended, and someone else had booked that time. Move this booking to a free time below. The price and the payment stay as they are. A time that is taken leaves this booking where it was.</p>
        </section>
    @elseif ($booking->attention_reason && in_array($status, ['held', 'pending_payment', 'confirmed'], true))
        <section class="notice attention" role="alert" data-needs-attention="flagged">
            <p><strong>Needs attention:</strong> {{ $booking->attention_reason }}</p>
            <p class="muted small">Nothing was cancelled or moved. Move the booking, change the resource, or contact the guest, then mark it handled.</p>
            @if ($canManage)
                <button type="button" class="button small" wire:click="clearAttention">Mark handled</button>
            @endif
        </section>
    @endif

    @if (in_array($status, ['held', 'pending_payment'], true) && $holdExpires)
        <p class="notice attention">
            @if ($status === 'pending_payment')
                Waiting for payment until {{ Display::time($holdExpires, $zone) }}.
            @else
                Held until {{ Display::time($holdExpires, $zone) }}. Take payment or confirm before then, or {{ $industry->holdExpiry() }}.
            @endif
        </p>
    @endif

    <div class="split">
        <div class="stack">
            <section class="panel">
                <h2>Details</h2>
                <dl class="facts">
                    <div><dt>Customer</dt><dd>{{ $customer?->name ?: 'Walk-in' }}@if($customer?->email)<br><a href="mailto:{{ $customer->email }}">{{ $customer->email }}</a>@endif@if($customer?->phone)<br><a href="tel:{{ $customer->phone }}" data-customer-phone="1">{{ $customer->phone }}</a>@endif</dd></div>
                    <div><dt>Party</dt><dd>{{ $booking->participants }} {{ $booking->participants === 1 ? 'person' : 'people' }}</dd></div>
                    @if ($booking->variant)
                        <div><dt>Option</dt><dd data-service-option="{{ $booking->variant->id }}">{{ $booking->variant->name }}</dd></div>
                    @endif
                    @if ($booking->corporateAccount)
                        <div><dt>Corporate account</dt><dd>{{ $booking->corporateAccount->name }}</dd></div>
                    @endif
                    @if ($booking->namedParticipants->isNotEmpty())
                        <div>
                            <dt>Named</dt>
                            <dd>
                                @foreach ($booking->namedParticipants as $person)
                                    <span class="tag" data-participant="{{ $person->id }}">{{ $person->name }}@if ($person->is_minor) <span class="muted">under 18, {{ $person->guardian_name }}</span>@endif</span>
                                @endforeach
                            </dd>
                        </div>
                    @endif
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
                    <div>
                        <dt>Guide</dt>
                        <dd data-guide="{{ $booking->guide_user_id ?? 'none' }}">{{ $guideName !== '' ? $guideName : 'No guide' }}</dd>
                    </div>
                </dl>
                @if ($guestManageUrl)
                    <div class="payment-link" x-data="{ copied: false, copy(text) { const done = () => { this.copied = true; setTimeout(() => { this.copied = false }, 2000) }; const fallback = () => { const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done() }; try { navigator.clipboard ? navigator.clipboard.writeText(text).then(done, fallback) : fallback() } catch (e) { fallback() } } }">
                        <p><strong>Guest manage link</strong></p>
                        <p class="muted small">Send this to the guest, or open it. A confirmed rental can be moved from that page. The price stays the same.</p>
                        <a class="button" href="{{ $guestManageUrl }}" target="_blank" rel="noopener">Open guest page</a>
                        <input type="text" readonly data-guest-manage-link="1" value="{{ $guestManageUrl }}" aria-label="Guest manage link" onclick="this.select()">
                        <button type="button" class="button" x-on:click="copy(@js($guestManageUrl))"><span x-text="copied ? 'Copied' : 'Copy link'">Copy link</span></button>
                    </div>
                @endif
                @if ($canAssignGuide)
                    <form wire:submit="assignGuide" class="stack top-gap">
                        <label class="field">
                            <span>Assign a guide</span>
                            <select wire:model="guideUserId">
                                <option value="">No guide</option>
                                @foreach ($guides as $guide)
                                    <option value="{{ $guide->id }}">{{ $guide->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="button">Save guide</button>
                        <p class="muted small">People on the same departure share this guide. A guide who is already booked keeps that other booking. The price stays the same.</p>
                    </form>
                @endif
            </section>

            <section class="panel waiver-copies" data-signed-waivers>
                <style>
                    @media (max-width: 390px) {
                        .waiver-copies,
                        .waiver-copies li,
                        .waiver-copies a,
                        .waiver-copies .muted { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
                    }
                </style>
                <h2>Signed waivers</h2>
                @if ($booking->waiverSignatures->isEmpty())
                    <p class="muted">No one has signed a waiver for this booking.</p>
                @else
                    <ul class="stack">
                        @foreach ($booking->waiverSignatures as $signature)
                            <li>
                                <strong>{{ $signature->participant_name }}</strong>
                                @if ($signature->is_minor)
                                    <span class="muted">under 18, {{ $signature->guardian_name }}</span>
                                @endif
                                <span class="muted">
                                    {{ $signature->version?->name ?? 'Waiver' }}
                                    @if ($signature->version)
                                        version {{ $signature->version->version }}
                                    @endif
                                    @if ($signature->signed_at)
                                        · {{ Display::dateTime($signature->signed_at, $zone) }}
                                    @endif
                                </span>
                                @if ($signature->version)
                                    <a class="button small" href="/staff/bookings/{{ $booking->id }}/waivers/{{ $signature->id }}">Download PDF</a>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                    <p class="muted small">The file is the stored waiver text and signature. It is not an electronic-signature certificate.</p>
                @endif
            </section>

            <section class="panel">
                <h2>Price</h2>
                @if ($snapshot)
                    @include('disclosures.checkout-summary', [
                        'subtotalCents' => max(0, (int) ($p['basis_cents'] ?? 0) - $operatorCents),
                        'operatorFees' => $operatorFees,
                        'bookingFeeCents' => $p['customer_fee_line_cents'] ?? null,
                        'taxCents' => (int) ($p['tax_cents'] ?? 0),
                        'taxLines' => is_array($p['tax_lines'] ?? null) ? $p['tax_lines'] : [],
                        'totalCents' => (int) ($p['customer_total_cents'] ?? 0),
                        'currency' => $currency,
                        'refundTerms' => $disclosure,
                    ])
                    @php
                        $groupBands = is_array($p['party_bands'] ?? null) ? $p['party_bands'] : [];
                        $groupDetail = GroupPriceCopy::detail($groupBands);
                    @endphp
                    @if ($groupDetail)
                        <p data-group-price="{{ (int) ($p['customer_total_cents'] ?? 0) }}"><strong>{{ GroupPriceCopy::label($groupBands) }}</strong> {{ $groupDetail }}</p>
                    @endif
                    @if ((int) ($p['damage_cents'] ?? 0) > 0)
                        <p data-damage="{{ (int) $p['damage_cents'] }}">Damage {{ Display::money((int) $p['damage_cents'], $currency) }} is included in the total. It is not a booking fee.</p>
                    @endif
                    @if (isset($p['charge_cents']) && (int) $p['charge_cents'] !== (int) ($p['customer_total_cents'] ?? 0))
                        <p class="muted">Due now: {{ Display::money((int) $p['charge_cents'], $currency) }}{{ ! empty($p['deposit_was_raised']) ? ' (deposit raised to cover the booking fee)' : '' }}.</p>
                    @endif
                @elseif ($canManage && $open)
                    <p class="muted">Price this booking before taking payment.</p>
                    <form wire:submit="price" class="form-row">
                        @if ($hasLocationTax)
                            <p class="muted">Tax comes from this location's rates.</p>
                        @else
                            <label class="field compact">
                                <span>Sales tax (%)</span>
                                <input type="number" min="0" max="100" step="0.01" wire:model="taxPercent">
                            </label>
                        @endif
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

            @if ($canReport)
                <section class="panel" id="damage-report">
                    <h2>Damage report</h2>
                    <livewire:staff.file-incident :booking-id="$booking->id" :key="'booking-incident-'.$booking->id" />
                </section>
            @endif

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
                    @if (in_array($status, ['held', 'pending_payment'], true))
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
                @endif

                @if ($open || $status === 'payment_exception')
                    <section class="panel recover-time">
                        <h2>Change time</h2>
                        @if ($booking->departure_id)
                            @if ($departures === [])
                                <p class="muted">No other departures are open.</p>
                            @else
                                <form wire:submit="reschedule" class="stack">
                                    <label class="field">
                                        <span>Departure</span>
                                        <select wire:model="rescheduleDeparture">
                                            <option value="">Choose a departure</option>
                                            @foreach ($departures as $departure)
                                                <option value="{{ $departure['id'] }}" @disabled(! $departure['available'])>{{ $departure['label'] }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    @error('rescheduleDeparture')<p class="error">{{ $message }}</p>@enderror
                                    <p class="muted small">The party keeps its price. A full or private departure stays closed.</p>
                                    <button type="submit" class="button">Reschedule</button>
                                </form>
                            @endif
                        @else
                            <form wire:submit="reschedule" class="stack">
                                <label class="field"><span>Start</span><input type="datetime-local" wire:model="rescheduleStart"></label>
                                <label class="field"><span>End</span><input type="datetime-local" wire:model="rescheduleEnd"></label>
                                @error('rescheduleEnd')<p class="error">{{ $message }}</p>@enderror
                                <button type="submit" class="button">Reschedule</button>
                            </form>
                        @endif
                        @if ($canExtend)
                            <form wire:submit="extend" class="stack top-gap">
                                <label class="field"><span>New return time</span><input type="datetime-local" wire:model="extendEnd"></label>
                                @error('extendEnd')<p class="error">{{ $message }}</p>@enderror
                                <button type="submit" class="button">Extend rental</button>
                            </form>
                            @if ($extensionShorterLabel)
                                <button type="button" class="button top-gap" wire:click="acceptShorterExtension">Extend until {{ $extensionShorterLabel }}</button>
                            @endif
                            @foreach ($extensionAssets as $offerAsset)
                                <button type="button" class="button top-gap" wire:click="acceptAssetExtension('{{ $offerAsset['id'] }}')">Use {{ $offerAsset['name'] }} instead</button>
                            @endforeach
                        @endif
                    </section>
                @endif

                @if ($open)
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
