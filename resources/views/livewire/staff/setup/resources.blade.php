@php use App\Support\Display; @endphp
<div class="page resources-page">
    <style>
        .resources-page .retire-panel { display: grid; gap: 0.6rem; min-width: 0; margin: 0 0 0.75rem; padding: 0.8rem 0.9rem; border: 1px solid var(--line); border-radius: var(--radius-lg); background: var(--surface); }
        .resources-page .retire-offers { list-style: none; margin: 0.35rem 0 0.75rem; padding: 0; display: grid; gap: 0.45rem; }
        .resources-page .retire-offers li { display: flex; flex-wrap: wrap; gap: 0.4rem 0.75rem; align-items: center; min-width: 0; }
        @media (max-width: 390px) {
            .resources-page .retire-panel,
            .resources-page .retire-panel *,
            .resources-page .retire-offers,
            .resources-page .retire-offers * { overflow-wrap: anywhere; min-width: 0; max-width: 100%; }
            .resources-page .retire-panel select,
            .resources-page .retire-panel .button,
            .resources-page .retire-offers .button { width: 100%; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>{{ $industry->catalog() }}</h1>
            <p class="lede">{{ $industry->resourcesLede() }}</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>
    @include('livewire.staff.partials.undo-toast')

    <section class="panel" id="fleet">
        <div class="panel-head">
            <h2>Shared fleet</h2>
            <a class="button small" href="/staff/load-sheet" wire:navigate>Daily load sheet</a>
        </div>
        <p class="muted">One count for the whole workspace. A truck or trailer takes that day’s load to each location marked delivered from fleet. Locations that keep their own gear stay as they are.</p>
        @if ($fleetItems->isEmpty())
            <p class="empty">No shared fleet yet.</p>
        @else
            <ul class="rows">
                @foreach ($fleetItems as $item)
                    <li class="row" id="fleet-{{ $item->id }}">
                        <span class="grow"><strong>{{ $item->name }}</strong> <span class="muted">whole workspace</span></span>
                        @if ($canManageFleet)
                            <label class="field inline"><span class="sr-only">Count of {{ $item->name }}</span>
                                <input type="number" min="0" max="100000" value="{{ $item->quantity }}" wire:change="setFleetQuantity('{{ $item->id }}', $event.target.value)" class="count">
                            </label>
                            <button type="button" class="button small" wire:click="deleteFleet('{{ $item->id }}')">Delete</button>
                        @else
                            <span>{{ $item->quantity }}</span>
                        @endif
                    </li>
                    @error('fleet-'.$item->id)<li class="error">{{ $message }}</li>@enderror
                @endforeach
            </ul>
        @endif
        @if ($canManageFleet)
            <form wire:submit="createFleet" class="form-row top-gap">
                <label class="field compact grow"><span>Fleet item</span><input type="text" wire:model="fleetName" maxlength="120" required placeholder="Single kayak"></label>
                <label class="field compact"><span>How many</span><input type="number" min="0" max="100000" wire:model="fleetQuantity" required></label>
                <button type="submit" class="button primary">Add to fleet</button>
            </form>
            @error('fleetName')<p class="error">{{ $message }}</p>@enderror
            @error('fleetQuantity')<p class="error">{{ $message }}</p>@enderror
        @endif
        <p class="muted small top-gap"><a href="/staff/setup/locations" wire:navigate>Locations</a> can be marked delivered from fleet. Link the item on a service so bookings draw from this count.</p>
    </section>

    @if ($locations->isEmpty())
        <section class="notice">
            <p>{{ $industry->needsLocation() }}</p>
            <p><a class="button" href="/staff/setup/locations" wire:navigate>Add a location</a></p>
        </section>
    @else
        <div class="split">
            <div class="stack">
                @foreach ($locations as $location)
                    <section class="panel">
                        <h2>{{ $location->name }}</h2>
                        @php $locPools = $pools->get($location->id, collect()); $locAssets = $assets->get($location->id, collect()); @endphp
                        @if ($locPools->isEmpty() && $locAssets->isEmpty())
                            <p class="empty">Nothing here yet.</p>
                        @endif
                        @if ($locPools->isNotEmpty())
                            <h3>Counted gear</h3>
                            <ul class="rows">
                                @foreach ($locPools as $pool)
                                    <li class="row" id="pool-{{ $pool->id }}">
                                        <span class="grow"><strong>{{ $pool->name }}</strong></span>
                                        @if ($canManage)
                                            <label class="field inline"><span class="sr-only">Count of {{ $pool->name }}</span>
                                                <input type="number" min="0" max="100000" value="{{ $pool->quantity }}" wire:change="setQuantity('{{ $pool->id }}', $event.target.value)" class="count">
                                            </label>
                                            <select aria-label="Status of {{ $pool->name }}" wire:change="setStatus('pool', '{{ $pool->id }}', $event.target.value)">
                                                @foreach ($statuses as $s)@continue($s->value === 'retired')<option value="{{ $s->value }}" @selected($pool->status === $s)>{{ Display::status($s->value) }}</option>@endforeach
                                            </select>
                                            <button type="button" class="button small" wire:click="deleteResource('pool', '{{ $pool->id }}')">Delete</button>
                                        @else
                                            <span>{{ $pool->quantity }}</span>
                                            <span class="muted">{{ Display::status($pool->status->value) }}</span>
                                        @endif
                                    </li>
                                    @error('pool-'.$pool->id)<li class="error">{{ $message }}</li>@enderror
                                @endforeach
                            </ul>
                        @endif
                        @if ($locAssets->isNotEmpty())
                            <h3>Individual items and guides</h3>
                            <ul class="rows">
                                @foreach ($locAssets as $asset)
                                    <li class="row" id="asset-{{ $asset->id }}">
                                        <span class="grow"><strong>{{ $asset->name }}</strong> <span class="muted">{{ $asset->is_guide ? 'Guide' : 'Seats '.$asset->capacity }}</span>@if ($asset->status->value === 'retired' && $asset->replacement) <span class="muted">· replacement {{ $asset->replacement->name }}</span>@endif</span>
                                        @if ($canManage)
                                            <select aria-label="Status of {{ $asset->name }}" wire:change="setStatus('asset', '{{ $asset->id }}', $event.target.value)">
                                                @foreach ($statuses as $s)@continue($s->value === 'retired' && $asset->status !== $s)<option value="{{ $s->value }}" @selected($asset->status === $s)>{{ Display::status($s->value) }}</option>@endforeach
                                            </select>
                                            @if ($asset->status->value !== 'retired')
                                                <button type="button" class="button small" wire:click="openRetire('{{ $asset->id }}')">Retire</button>
                                            @endif
                                            <button type="button" class="button small" wire:click="deleteResource('asset', '{{ $asset->id }}')">Delete</button>
                                        @else
                                            <span class="muted">{{ Display::status($asset->status->value) }}</span>
                                        @endif
                                    </li>
                                    @error('asset-'.$asset->id)<li class="error">{{ $message }}</li>@enderror
                                    @if ($retireAssetId === $asset->id)
                                        <li class="retire-panel" id="retire-{{ $asset->id }}">
                                            <form wire:submit="retire" class="stack">
                                                <p>Future bookings stay booked. A replacement has to be another active item of the same type at this location. Moving one later does not change the price.</p>
                                                <label class="field"><span>Replacement</span>
                                                    <select wire:model="replacementId" aria-label="Replacement for {{ $asset->name }}">
                                                        <option value="">No replacement</option>
                                                        @foreach ($retirement[$asset->id]['candidates'] ?? [] as $candidate)
                                                            <option value="{{ $candidate['id'] }}">{{ $candidate['name'] }}</option>
                                                        @endforeach
                                                    </select>
                                                </label>
                                                @error('replacement_asset_id')<p class="error">{{ $message }}</p>@enderror
                                                @error('status')<p class="error">{{ $message }}</p>@enderror
                                                <div class="form-row">
                                                    <button type="submit" class="button primary">Retire {{ $asset->name }}</button>
                                                    <button type="button" class="button" wire:click="cancelRetire">Cancel</button>
                                                </div>
                                            </form>
                                        </li>
                                    @endif
                                    @if ($asset->status->value === 'retired' && ($retirement[$asset->id]['offers'] ?? []) !== [])
                                        <li>
                                            <ul class="retire-offers">
                                                @foreach ($retirement[$asset->id]['offers'] as $offer)
                                                    <li data-retire-offer="{{ $offer['booking_id'] }}">
                                                        <a href="/staff/bookings/{{ $offer['booking_id'] }}" wire:navigate>{{ $offer['reference'] }} · {{ $offer['customer'] }} · {{ $offer['when'] }}</a>
                                                        @if ($canMoveBookings)
                                                            <button type="button" class="button small" wire:click="acceptOffer('{{ $offer['booking_id'] }}', '{{ $offer['from_asset_id'] }}', '{{ $offer['to_asset_id'] }}')">Move onto {{ $offer['to_name'] }}</button>
                                                        @endif
                                                        @error('move-'.$offer['booking_id'])<p class="error">{{ $message }}</p>@enderror
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </li>
                                    @endif
                                @endforeach
                            </ul>
                        @endif
                    </section>
                @endforeach
            </div>

            @if ($canManage)
                <section class="panel">
                    <h2>{{ $industry->addHeading() }}</h2>
                    <form wire:submit="create" class="stack">
                        <fieldset class="choice">
                            <legend>Type</legend>
                            <label class="check"><input type="radio" wire:model.live="kind" value="pool"><span>{{ $industry->countedExample() }}</span></label>
                            <label class="check"><input type="radio" wire:model.live="kind" value="asset"><span>{{ $industry->trackedExample() }}</span></label>
                        </fieldset>
                        <label class="field"><span>Location</span>
                            <select wire:model="locationId">
                                @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                            </select>
                        </label>
                        <label class="field"><span>Name</span><input type="text" wire:model="name" maxlength="120" required placeholder="{{ $kind === 'pool' ? $industry->poolPlaceholder() : $industry->assetPlaceholder() }}"></label>
                        @if ($kind === 'pool')
                            <label class="field"><span>How many</span><input type="number" min="0" max="100000" wire:model="quantity" required></label>
                        @else
                            <label class="field"><span>Seats</span><input type="number" min="1" max="10000" wire:model="capacity"></label>
                            <label class="check"><input type="checkbox" wire:model="isGuide"><span>This is a guide (a person, not gear)</span></label>
                        @endif
                        <label class="field"><span>Turnaround between bookings (minutes)</span><input type="number" min="0" max="1440" wire:model="turnaround"></label>
                        @error('name')<p class="error">{{ $message }}</p>@enderror
                        @error('quantity')<p class="error">{{ $message }}</p>@enderror
                        <button type="submit" class="button primary">Add</button>
                    </form>
                </section>
            @endif
        </div>
    @endif
</div>
