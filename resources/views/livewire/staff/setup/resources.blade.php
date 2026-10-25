@php use App\Support\Display; @endphp
<div class="page">
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
                                    <li class="row">
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
                                    <li class="row">
                                        <span class="grow"><strong>{{ $asset->name }}</strong> <span class="muted">{{ $asset->is_guide ? 'Guide' : 'Seats '.$asset->capacity }}</span></span>
                                        @if ($canManage)
                                            <select aria-label="Status of {{ $asset->name }}" wire:change="setStatus('asset', '{{ $asset->id }}', $event.target.value)">
                                                @foreach ($statuses as $s)@continue($s->value === 'retired' && $asset->status !== $s)<option value="{{ $s->value }}" @selected($asset->status === $s)>{{ Display::status($s->value) }}</option>@endforeach
                                            </select>
                                            <button type="button" class="button small" wire:click="deleteResource('asset', '{{ $asset->id }}')">Delete</button>
                                        @else
                                            <span class="muted">{{ Display::status($asset->status->value) }}</span>
                                        @endif
                                    </li>
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
