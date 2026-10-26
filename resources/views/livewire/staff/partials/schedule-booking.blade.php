@php
    use App\Enums\AddOnPricing;
    use App\Enums\ServiceKind;
    use App\Support\Display;
@endphp
@if ($booking)
@php
    $payload = is_array($booking['payload'] ?? null) ? $booking['payload'] : [];
    $hasTotal = is_numeric($payload['customer_total_cents'] ?? null);
    $operatorFees = is_array($payload['operator_fees'] ?? null) ? $payload['operator_fees'] : [];
    $serviceCents = is_numeric($payload['services_cents'] ?? null) ? (int) $payload['services_cents'] : 0;
    $addOnCents = is_numeric($payload['add_ons_cents'] ?? null) ? (int) $payload['add_ons_cents'] : 0;
    $discountCents = is_numeric($payload['discount_cents'] ?? null) ? (int) $payload['discount_cents'] : 0;
    $service = $booking['service'] ?? null;
    $isTour = $service?->kind === ServiceKind::Tour;
@endphp
    <section id="new-booking-panel" class="panel schedule-booking" aria-labelledby="new-booking-heading" tabindex="-1" wire:key="book-{{ $bookKey }}" x-data x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'nearest' }); })">
        <div class="panel-head">
            <h2 id="new-booking-heading">New booking</h2>
            <button type="button" class="button small" wire:click="closeBooking">Close</button>
        </div>

        @if ($bookProblem)
            <p class="flash bad" role="alert">{{ $bookProblem }}</p>
        @endif

        <p class="lede">{{ $booking['resourceName'] }}</p>
        <p>{{ $booking['when'] }} <span class="muted small">{{ $booking['zone'] }}</span></p>

        @if ($booking['services']->isEmpty())
            <p class="empty">No service uses this resource.</p>
        @else
            <div class="stack top-gap">
                <fieldset class="choice">
                    <legend>Service</legend>
                    <div class="choice-picks" role="group" aria-label="Service">
                        @foreach ($booking['services'] as $option)
                            <button type="button" class="button choice-pick {{ $bookService === $option->id ? 'primary' : '' }}" wire:click="chooseService('{{ $option->id }}')" @disabled($booking['placed']) aria-pressed="{{ $bookService === $option->id ? 'true' : 'false' }}">{{ $option->name }}</button>
                        @endforeach
                    </div>
                </fieldset>

                @if ($booking['variants']->isNotEmpty())
                    <fieldset class="choice">
                        <legend>Variant</legend>
                        <div class="choice-picks" role="group" aria-label="Variant">
                            @if ($booking['allowsBase'])
                                <button type="button" class="button choice-pick {{ $bookVariant === '' ? 'primary' : '' }}" wire:click="chooseVariant('')" @disabled($booking['placed']) aria-pressed="{{ $bookVariant === '' ? 'true' : 'false' }}">Standard</button>
                            @endif
                            @foreach ($booking['variants'] as $variant)
                                <button type="button" class="button choice-pick {{ $bookVariant === $variant->id ? 'primary' : '' }}" wire:click="chooseVariant('{{ $variant->id }}')" @disabled($booking['placed']) aria-pressed="{{ $bookVariant === $variant->id ? 'true' : 'false' }}">{{ $variant->name }}</button>
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                @if ($isTour)
                    <fieldset class="choice">
                        <legend>Departure</legend>
                        @if ($booking['departures']->isEmpty())
                            <p class="empty">No departures on this date.</p>
                        @else
                            <div class="choice-picks" role="group" aria-label="Departure">
                                @foreach ($booking['departures'] as $departure)
                                    <button type="button" class="button choice-pick {{ $bookDeparture === $departure->id ? 'primary' : '' }}" wire:click="chooseDeparture('{{ $departure->id }}')" @disabled($booking['placed'])>{{ Display::time($departure->starts_at, $booking['zone']) }}</button>
                                @endforeach
                            </div>
                        @endif
                    </fieldset>
                @else
                    <fieldset class="choice">
                        <legend>Start</legend>
                        <div class="hour-picks" role="group" aria-label="Start time">
                            @foreach ($booking['starts'] as $choice)
                                <button type="button" class="button hour-pick {{ $bookStart === $choice['start'] ? 'primary' : '' }}" wire:click="chooseStart('{{ $choice['start'] }}')" @disabled($booking['placed']) data-start="{{ $choice['start'] }}">{{ $choice['label'] }}</button>
                            @endforeach
                        </div>
                    </fieldset>

                    @if ($booking['durations'] !== [])
                        <fieldset class="choice">
                            <legend>Length</legend>
                            <div class="choice-picks" role="group" aria-label="Length">
                                @foreach ($booking['durations'] as $minutes)
                                    <button type="button" class="button choice-pick {{ (int) $bookDuration === (int) $minutes ? 'primary' : '' }}" wire:click="chooseDuration({{ (int) $minutes }})" @disabled($booking['placed'])>{{ $minutes % 60 === 0 ? (intdiv((int) $minutes, 60) === 1 ? '1 hour' : intdiv((int) $minutes, 60).' hours') : $minutes.' min' }}</button>
                                @endforeach
                            </div>
                        </fieldset>
                    @endif
                @endif

                <div class="party-step">
                    <span>Party</span>
                    <button type="button" class="button choice-pick" wire:click="stepParty(-1)" aria-label="Fewer people" @disabled($booking['placed'])>−</button>
                    <strong>{{ $bookParty }}</strong>
                    <button type="button" class="button choice-pick" wire:click="stepParty(1)" aria-label="More people" @disabled($booking['placed'])>+</button>
                </div>

                @if ($booking['addOns']->isNotEmpty())
                    <fieldset class="choice">
                        <legend>Add-ons</legend>
                        <div class="choice-picks">
                            @foreach ($booking['addOns'] as $addOn)
                                @if ($addOn->pricing === AddOnPricing::Each)
                                    <label class="field compact">
                                        <span>{{ $addOn->name }} ({{ Display::money($addOn->amount_cents, $booking['currency']) }} each)</span>
                                        <input type="number" min="0" @if ($addOn->max_quantity) max="{{ $addOn->max_quantity }}" @endif wire:model.live="bookAddOns.{{ $addOn->id }}" @disabled($booking['placed'])>
                                    </label>
                                @else
                                    <button type="button" class="button choice-pick {{ (int) ($bookAddOns[$addOn->id] ?? 0) > 0 ? 'primary' : '' }}" wire:click="toggleAddOn('{{ $addOn->id }}')" @disabled($booking['placed'])>{{ $addOn->name }} · {{ Display::money($addOn->amount_cents, $booking['currency']) }}</button>
                                @endif
                            @endforeach
                        </div>
                    </fieldset>
                @endif

                <label class="field">
                    <span>Guide</span>
                    <select wire:model="bookGuide" @disabled($booking['placed'])>
                        <option value="">No guide</option>
                        @foreach ($booking['guides'] as $guide)
                            <option value="{{ $guide->id }}">{{ $guide->name }}</option>
                        @endforeach
                    </select>
                </label>

                <fieldset class="choice">
                    <legend>Customer</legend>
                    @if ($bookCustomerId === '')
                        <label class="field">
                            <span>Find an existing customer</span>
                            <input type="search" wire:model.live.debounce.300ms="bookCustomerQuery" maxlength="120" autocomplete="off" placeholder="Name, email, or phone" @disabled($booking['placed'])>
                        </label>
                        @if ($booking['customers']->isNotEmpty())
                            <div class="choice-picks">
                                @foreach ($booking['customers'] as $customer)
                                    <button type="button" class="button choice-pick" wire:click="selectCustomer('{{ $customer->id }}')" @disabled($booking['placed'])>{{ $customer->name }}@if ($customer->email) · {{ $customer->email }}@endif</button>
                                @endforeach
                            </div>
                        @endif
                        <label class="field">
                            <span>Name</span>
                            <input type="text" wire:model="bookCustomerName" maxlength="120" autocomplete="off" required @disabled($booking['placed'])>
                        </label>
                        @error('bookCustomerName')<p class="error">{{ $message }}</p>@enderror
                        <label class="field">
                            <span>Email (optional)</span>
                            <input type="email" wire:model="bookCustomerEmail" maxlength="255" autocomplete="off" @disabled($booking['placed'])>
                        </label>
                        @error('bookCustomerEmail')<p class="error">{{ $message }}</p>@enderror
                        <label class="field">
                            <span>Phone (optional)</span>
                            <input type="tel" wire:model="bookCustomerPhone" maxlength="255" autocomplete="off" @disabled($booking['placed'])>
                        </label>
                    @else
                        <p>{{ $bookCustomerName }}@if ($bookCustomerEmail) · {{ $bookCustomerEmail }}@endif</p>
                        <button type="button" class="button small" wire:click="clearCustomer" @disabled($booking['placed'])>Choose someone else</button>
                    @endif
                </fieldset>

                @if ($booking['quoteError'])
                    <p class="flash bad" role="alert">{{ $booking['quoteError'] }}</p>
                @endif

                @if ($hasTotal)
                    @include('disclosures.checkout-summary', [
                        'subtotalCents' => max(0, $serviceCents - $discountCents + $addOnCents),
                        'operatorFees' => $operatorFees,
                        'bookingFeeCents' => $payload['customer_fee_line_cents'] ?? null,
                        'taxCents' => (int) ($payload['tax_cents'] ?? 0),
                        'taxLines' => is_array($payload['tax_lines'] ?? null) ? $payload['tax_lines'] : [],
                        'totalCents' => (int) $payload['customer_total_cents'],
                        'currency' => $booking['currency'],
                        'refundTerms' => $booking['disclosure'],
                    ])
                @endif

                <div class="actions">
                    @if ($booking['placed'])
                        <a class="button" href="/staff/bookings/{{ $bookId }}">Open booking</a>
                    @else
                        <button type="button" class="button" wire:click="holdBooking" wire:loading.attr="disabled" wire:target="holdBooking,payAtPickup,sendPaymentLink">Hold</button>
                    @endif
                    @if (is_int($booking['pickupCents']))
                        <button type="button" class="button primary" wire:click="payAtPickup" wire:loading.attr="disabled" wire:target="holdBooking,payAtPickup,sendPaymentLink">Pay {{ Display::money($booking['pickupCents'], $booking['currency']) }} at pickup</button>
                    @endif
                    @if ($booking['canCharge'])
                        <button type="button" class="button" wire:click="sendPaymentLink" wire:loading.attr="disabled" wire:target="holdBooking,payAtPickup,sendPaymentLink">Send payment link</button>
                    @else
                        <p class="muted small">Online payments are not enabled for this workspace.</p>
                    @endif
                </div>

                @if ($bookPaymentUrl)
                    <p class="payment-link">
                        <a class="button primary" href="{{ $bookPaymentUrl }}" target="_blank" rel="noopener">Open card payment</a>
                        <input type="text" readonly value="{{ $bookPaymentUrl }}" aria-label="Payment link" onclick="this.select()">
                    </p>
                @endif
            </div>
        @endif
    </section>
@endif
