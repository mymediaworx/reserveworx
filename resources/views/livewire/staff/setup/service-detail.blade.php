@php
    use App\Support\Display;
    $isTour = $service->kind->value === 'tour';
    $kindLabels = ['base' => 'Per booking', 'per_person' => 'Per person', 'duration' => 'For a length'];
    $windowLabels = ['always' => 'Every day', 'weekday' => 'Weekdays', 'weekend' => 'Weekends'];
@endphp
<div class="page service-detail">
    <style>
        @media (max-width: 390px) {
            .service-detail,
            .service-detail .panel,
            .service-detail .split,
            .service-detail .stack,
            .service-detail .row,
            .service-detail .form-row,
            .service-detail .field { min-width: 0; max-width: 100%; }
            .service-detail h1,
            .service-detail .lede,
            .service-detail .row,
            .service-detail .field span { overflow-wrap: anywhere; }
            .service-detail .form-row { display: grid; grid-template-columns: minmax(0, 1fr); }
            .service-detail .row { flex-wrap: wrap; }
            .service-detail .row .grow { flex-basis: 100%; }
            .service-detail .row .button,
            .service-detail .form-row .button,
            .service-detail .field input,
            .service-detail .field select { width: 100%; max-width: 100%; min-width: 0; }
            .service-detail .row .button { min-height: 44px; white-space: normal; }
        }
    </style>
    <p class="crumb"><a href="/staff/setup/services" wire:navigate>Services & prices</a></p>
    <header class="page-head">
        <div>
            <h1>{{ $service->name }}</h1>
            <p class="lede">{{ $isTour ? 'Tour' : 'Rental' }} at {{ $service->location?->name }}</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    @php
        $missing = [];
        if ($prices->isEmpty()) { $missing[] = 'a price'; }
        if ($service->requirements->isEmpty()) { $missing[] = 'the gear it uses'; }
        if (! $isTour && $service->durations->isEmpty()) { $missing[] = 'at least one rental length'; }
        if ($isTour && $departures->isEmpty()) { $missing[] = 'a departure'; }
    @endphp
    @if ($missing !== [])
        <p class="notice attention">To take bookings, add {{ implode(', ', $missing) }}.</p>
    @endif

    <div class="split even">
        <section class="panel">
            <h2>Prices</h2>
            <p class="muted small">Prices that match a booking are added together. For example, $20 per booking plus $15 per person. A price for a party size replaces the regular price of that same kind when the party is in range. A percent off applies to that total. A named account uses its own rate.</p>
            @if ($prices->isEmpty())
                <p class="empty">No prices yet.</p>
            @else
                <ul class="rows compact">
                    @foreach ($prices as $rule)
                        <li class="row">
                            <span class="grow"><strong>{{ $rule->name }}</strong> <span class="muted">{{ $rule->percent_off ? 'Percent off' : ($kindLabels[$rule->kind] ?? $rule->kind) }}{{ $rule->duration_minutes ? ' ('.Display::minutes($rule->duration_minutes).')' : '' }}, {{ $windowLabels[$rule->window] ?? $rule->window }}@if ($rule->min_participants) · {{ $rule->max_participants ? $rule->min_participants.'–'.$rule->max_participants.' people' : $rule->min_participants.'+ people' }}@endif @if ($rule->corporateAccount) · {{ $rule->corporateAccount->name }}@endif</span></span>
                            <span class="num">{{ $rule->percent_off ? $rule->percent_off.'% off' : Display::money($rule->amount_cents) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($canManage)
                <form wire:submit="addPrice" class="stack top-gap price-form">
                    <div class="form-row">
                        <label class="field compact grow"><span>Name</span><input type="text" wire:model="priceName" maxlength="80" required placeholder="Half day"></label>
                        <label class="field compact"><span>Rate</span>
                            <select wire:model.live="priceMode">
                                <option value="amount">Dollar amount</option>
                                <option value="percent">Percent off</option>
                            </select>
                        </label>
                        @if ($priceMode === 'percent')
                            <label class="field compact"><span>Percent off</span><input type="number" min="1" max="100" wire:model="pricePercent" required></label>
                        @else
                            <label class="field compact"><span>Amount ($)</span><input type="number" min="0" step="0.01" wire:model="priceAmount" required></label>
                        @endif
                    </div>
                    <div class="form-row">
                        @if ($priceMode !== 'percent')
                        <label class="field compact"><span>Charged</span>
                            <select wire:model.live="priceKind">
                                @foreach ($kindLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </label>
                        @endif
                        @if ($priceMode !== 'percent' && $priceKind === 'duration')
                            <label class="field compact"><span>Length</span>
                                <select wire:model="priceDuration">
                                    <option value="">Choose</option>
                                    @foreach ($service->durations->sortBy('duration_minutes') as $d)<option value="{{ $d->duration_minutes }}">{{ $d->name }}</option>@endforeach
                                </select>
                            </label>
                        @endif
                        <label class="field compact"><span>Days</span>
                            <select wire:model="priceWindow">
                                @foreach ($windowLabels as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                            </select>
                        </label>
                        <label class="field compact"><span>Party from</span><input type="number" min="1" max="500" inputmode="numeric" wire:model="priceMinParty" placeholder="Any"></label>
                        <label class="field compact"><span>Party to</span><input type="number" min="1" max="500" inputmode="numeric" wire:model="priceMaxParty" placeholder="No max"></label>
                        <label class="field compact"><span>Account</span>
                            <select wire:model="priceAccount">
                                <option value="">Every guest</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="button">Add price</button>
                    </div>
                    @error('priceAmount')<p class="error">{{ $message }}</p>@enderror
                    @error('pricePercent')<p class="error">{{ $message }}</p>@enderror
                    @error('priceAccount')<p class="error">{{ $message }}</p>@enderror
                    @error('priceDuration')<p class="error">Choose which length this price is for. Add lengths first.</p>@enderror
                    @error('priceMinParty')<p class="error">{{ $message }}</p>@enderror
                    @error('priceMaxParty')<p class="error">{{ $message }}</p>@enderror
                </form>
            @endif
        </section>

        <section class="panel">
            <h2>Gear used</h2>
            @if ($service->requirements->isEmpty())
                <p class="empty">Not linked to any gear yet, so it cannot be booked.</p>
            @else
                <ul class="rows compact">
                    @foreach ($service->requirements as $req)
                        <li class="row">
                            <span class="grow">{{ $resourceNames[$req->resource_id] ?? 'Gear' }}</span>
                            <span class="muted">{{ $req->quantity }}{{ $req->per_participants ? ' for every '.$req->per_participants.' '.($req->per_participants === 1 ? 'person' : 'people') : ' per booking' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($canManage)
                @if ($pools->isEmpty() && $assets->isEmpty() && $fleetItems->isEmpty())
                    <p class="muted">There is no gear at {{ $service->location?->name }} yet. <a href="/staff/setup/resources" wire:navigate>Add gear</a>.</p>
                @else
                    <form wire:submit="addRequirement" class="form-row top-gap">
                        <label class="field compact grow"><span>Gear</span>
                            <select wire:model="requirementResource" required>
                                <option value="">Choose</option>
                                @foreach ($fleetItems as $item)<option value="fleet:{{ $item->id }}">{{ $item->name }} (fleet {{ $item->quantity }})</option>@endforeach
                                @foreach ($pools as $pool)<option value="pool:{{ $pool->id }}">{{ $pool->name }} ({{ $pool->quantity }})</option>@endforeach
                                @foreach ($assets as $asset)<option value="asset:{{ $asset->id }}">{{ $asset->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="field compact"><span>How many</span><input type="number" min="1" wire:model="requirementQuantity" required></label>
                        <label class="field compact"><span>For every N people</span><input type="number" min="1" wire:model="requirementPer" placeholder="Per booking"></label>
                        <button type="submit" class="button">Link gear</button>
                    </form>
                    @error('requirementResource')<p class="error">{{ $message }}</p>@enderror
                @endif
            @endif
        </section>

        @if (! $isTour)
            <section class="panel">
                <h2>Rental lengths</h2>
                @if ($service->durations->isEmpty())
                    <p class="empty">No lengths yet.</p>
                @else
                    <ul class="rows compact">
                        @foreach ($service->durations->sortBy('duration_minutes') as $d)
                            <li class="row"><span class="grow">{{ $d->name }}</span><span class="muted">{{ Display::minutes($d->duration_minutes) }}</span></li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManage)
                    <form wire:submit="addDuration" class="form-row top-gap">
                        <label class="field compact grow"><span>Label</span><input type="text" wire:model="durationName" maxlength="80" required placeholder="2 hours"></label>
                        <label class="field compact"><span>Length</span><input type="number" min="1" wire:model="durationQuantity" required></label>
                        <label class="field compact"><span>Unit</span>
                            <select wire:model="durationUnit"><option value="hours">Hours</option><option value="minutes">Minutes</option></select>
                        </label>
                        <button type="submit" class="button">Add length</button>
                    </form>
                    @error('durationQuantity')<p class="error">{{ $message }}</p>@enderror
                @endif
            </section>
        @else
            <section class="panel">
                <h2>Departures</h2>
                @if ($departures->isEmpty())
                    <p class="empty">No upcoming departures.</p>
                @else
                    <ul class="rows compact">
                        @foreach ($departures as $departure)
                            <li class="row">
                                <span class="grow">{{ Display::dateTime($departure->starts_at, $zone) }}</span>
                                <span class="muted">{{ $departure->private ? 'Private' : $departure->headcount().' of '.$departure->seats }}{{ $departure->status->value === 'cancelled' ? ', cancelled' : '' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManage)
                    <form wire:submit="addDeparture" class="stack top-gap">
                        <div class="form-row">
                            <label class="field compact"><span>Date</span><input type="date" wire:model="departureDate" required></label>
                            <label class="field compact"><span>Time</span><input type="time" wire:model="departureTime" required></label>
                            <label class="field compact"><span>Minutes</span><input type="number" min="15" max="1440" wire:model="departureMinutes" required></label>
                        </div>
                        <div class="form-row">
                            <label class="check"><input type="checkbox" wire:model.live="departurePrivate"><span>Private (one group)</span></label>
                            @unless ($departurePrivate)
                                <label class="field compact"><span>Seats</span><input type="number" min="1" wire:model="departureSeats" required></label>
                            @endunless
                            <button type="submit" class="button">Schedule departure</button>
                        </div>
                    </form>
                @endif
            </section>
        @endif

        @if ($canViewVariants)
        <section class="panel" aria-labelledby="options-heading">
            <h2 id="options-heading">Options</h2>
            <p class="muted small">A tandem, a shorter rental, or another way to book this service. The schedule offers an option that uses the boat or pool you tap. Leave the price blank to keep the service price. Tax, the booking fee, and refunds stay as they are.</p>
            @if ($variantProblem)<p class="error" role="alert">{{ $variantProblem }}</p>@endif
            @if ($variants->isEmpty())
                <p class="empty">No options yet.</p>
            @else
                <ul class="rows compact">
                    @foreach ($variants as $variant)
                        @php
                            $gear = $variant->resources
                                ->map(fn ($row) => $resourceNames[$row->resource_id] ?? 'Gear')
                                ->implode(', ');
                        @endphp
                        <li class="row" wire:key="variant-{{ $variant->id }}">
                            <span class="grow">
                                <strong>{{ $variant->name }}</strong>
                                <span class="muted">{{ Display::minutes($variant->duration_minutes) }} · {{ $variant->price_override_cents === null ? 'Service price' : Display::money($variant->price_override_cents) }}@if ($gear !== '') · {{ $gear }}@endif</span>
                            </span>
                            @if ($canManageVariants)
                                <button type="button" class="button small" wire:click="editVariant('{{ $variant->id }}')">Edit</button>
                                <button type="button" class="button small" wire:click="deleteVariant('{{ $variant->id }}')">Remove</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($canManageVariants)
                <form wire:submit="saveVariant" class="stack top-gap">
                    <div class="form-row">
                        <label class="field compact grow"><span>Name</span><input type="text" wire:model="variantName" maxlength="80" required placeholder="Tandem"></label>
                        <label class="field compact"><span>Length</span><input type="number" min="1" wire:model="variantQuantity" required></label>
                        <label class="field compact"><span>Unit</span>
                            <select wire:model="variantUnit"><option value="hours">Hours</option><option value="minutes">Minutes</option></select>
                        </label>
                    </div>
                    <div class="form-row">
                        <label class="field compact"><span>Price ($) <span class="muted">optional</span></span><input type="text" inputmode="decimal" wire:model="variantPrice" placeholder="Service price"></label>
                        <label class="field compact grow"><span>Gear <span class="muted">optional</span></span>
                            <select wire:model="variantResource">
                                <option value="">No specific gear</option>
                                @foreach ($variantPools as $pool)<option value="pool:{{ $pool->id }}">{{ $pool->name }}</option>@endforeach
                                @foreach ($variantAssets as $asset)<option value="asset:{{ $asset->id }}">{{ $asset->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="field compact"><span>How many</span><input type="number" min="1" wire:model="variantGearQuantity" required></label>
                        <label class="field compact"><span>People each <span class="muted">optional</span></span><input type="number" min="1" wire:model="variantCapacity" placeholder="As entered"></label>
                    </div>
                    @error('variantName')<p class="error">{{ $message }}</p>@enderror
                    @error('variantQuantity')<p class="error">{{ $message }}</p>@enderror
                    @error('variantPrice')<p class="error">{{ $message }}</p>@enderror
                    @error('variantResource')<p class="error">{{ $message }}</p>@enderror
                    @error('variantGearQuantity')<p class="error">{{ $message }}</p>@enderror
                    @error('variantCapacity')<p class="error">{{ $message }}</p>@enderror
                    <div class="form-row">
                        <button type="submit" class="button">{{ $editingVariantId === '' ? 'Add option' : 'Save option' }}</button>
                        @if ($editingVariantId !== '')
                            <button type="button" class="button" wire:click="cancelVariant">Cancel</button>
                        @endif
                    </div>
                </form>
            @endif
        </section>
        @endif

        <section class="panel">
            <h2>Group size and waitlist</h2>
            <form wire:submit="saveSettings" class="stack">
                <div class="form-row">
                    <label class="field compact"><span>Minimum people</span><input type="number" min="1" wire:model="minParticipants" @disabled(! $canManage)></label>
                    <label class="field compact"><span>Maximum people</span><input type="number" min="1" wire:model="maxParticipants" @disabled(! $canManage)></label>
                </div>
                <label class="check"><input type="checkbox" wire:model="waitlist" @disabled(! $canManage)><span>Offer a waitlist when it is full</span></label>
                @error('maxParticipants')<p class="error">{{ $message }}</p>@enderror
                @if ($canManage)<button type="submit" class="button">Save</button>@endif
            </form>
        </section>
    </div>
</div>
