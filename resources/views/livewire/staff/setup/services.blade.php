@php use App\Support\Display; @endphp
<div class="page">
    <header class="page-head">
        <div>
            <h1>Services & prices</h1>
            <p class="lede">What customers can book: rentals by the hour or day, and tours with set departures.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    <div class="split">
        <div class="stack">
            <section class="panel">
                <h2>Services</h2>
                @if ($services->isEmpty())
                    <p class="empty">No services yet. Add a rental or a tour to open bookings.</p>
                @else
                    <ul class="rows">
                        @foreach ($services as $service)
                            <li class="row">
                                <a class="grow" href="/staff/setup/services/{{ $service->id }}" wire:navigate>
                                    <strong>{{ $service->name }}</strong>
                                    <span class="muted">{{ $service->kind->value === 'tour' ? 'Tour' : 'Rental' }} at {{ $service->location?->name }}</span>
                                </a>
                                @if ($fromPrices->has($service->id))
                                    <span>from {{ Display::money($fromPrices->get($service->id)) }}</span>
                                @else
                                    <span class="badge badge-held">No price</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="panel">
                <h2>Promo codes</h2>
                <p class="muted">Dates, a use limit, and edits are on <a href="/staff/promo-codes" wire:navigate>Promo codes</a>.</p>
                @if ($promos->isEmpty())
                    <p class="muted">No promo codes.</p>
                @else
                    <ul class="rows compact">
                        @foreach ($promos as $promo)
                            <li class="row">
                                <span class="grow"><code>{{ $promo->code }}</code> <span class="muted">{{ $promo->percent_off ? $promo->percent_off.'% off' : Display::money((int) $promo->amount_off_cents).' off' }}</span></span>
                                @if ($canManage)
                                    <button type="button" class="button small" wire:click="togglePromo('{{ $promo->id }}')">{{ $promo->active ? 'Turn off' : 'Turn on' }}</button>
                                @else
                                    <span class="muted">{{ $promo->active ? 'On' : 'Off' }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManage)
                    <form wire:submit="createPromo" class="form-row top-gap">
                        <label class="field compact"><span>Code</span><input type="text" wire:model="promoCode" maxlength="40" required placeholder="SUMMER"></label>
                        <label class="field compact"><span>Type</span>
                            <select wire:model.live="promoType"><option value="percent">Percent off</option><option value="amount">Dollars off</option></select>
                        </label>
                        <label class="field compact"><span>{{ $promoType === 'percent' ? 'Percent' : 'Dollars' }}</span><input type="number" min="1" wire:model="promoValue" required></label>
                        <button type="submit" class="button">Add code</button>
                    </form>
                    @error('promoCode')<p class="error">{{ $message }}</p>@enderror
                    @error('promoValue')<p class="error">{{ $message }}</p>@enderror
                @endif
            </section>
        </div>

        @if ($canManage)
            <section class="panel">
                <h2>Add a service</h2>
                @if ($locations->isEmpty())
                    <p class="muted">Add a location first.</p>
                @else
                    <form wire:submit="create" class="stack">
                        <label class="field"><span>Name</span><input type="text" wire:model="name" maxlength="120" required placeholder="Single kayak rental"></label>
                        <fieldset class="choice">
                            <legend>Type</legend>
                            <label class="check"><input type="radio" wire:model="kind" value="rental"><span>Rental: customer picks a start time and length</span></label>
                            <label class="check"><input type="radio" wire:model="kind" value="tour"><span>Tour: customer joins a scheduled departure</span></label>
                        </fieldset>
                        <label class="field"><span>Location</span>
                            <select wire:model="locationId">
                                @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                            </select>
                        </label>
                        @error('name')<p class="error">{{ $message }}</p>@enderror
                        <button type="submit" class="button primary">Add and set up</button>
                    </form>
                @endif
            </section>
        @endif
    </div>
</div>
