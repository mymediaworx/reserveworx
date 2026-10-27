@php use App\Support\Display; @endphp
<div class="page addons-page">
    <style>
        @media (max-width: 430px) {
            body.app:has(.fab):has(.addons-page) {
                height: auto;
                min-height: 100dvh;
                overflow: visible;
            }
            body.app:has(.fab):has(.addons-page) .app-shell,
            body.app:has(.fab):has(.addons-page) .app-main {
                height: auto;
                max-height: none;
                min-height: 0;
                overflow: visible;
            }
            body.app:has(.fab):has(.addons-page) .app-content {
                padding-bottom: calc(var(--tabbar) + var(--safe-bottom) + 5.5rem);
            }
            .addons-page,
            .addons-page .panel,
            .addons-page .split,
            .addons-page .stack,
            .addons-page .row,
            .addons-page .field { min-width: 0; max-width: 100%; }
            .addons-page h1,
            .addons-page .lede,
            .addons-page .row,
            .addons-page .field span { overflow-wrap: anywhere; }
            .addons-page .row { flex-wrap: wrap; }
            .addons-page .row .button,
            .addons-page .stack .button,
            .addons-page .field input,
            .addons-page .field select,
            .addons-page .field textarea { width: 100%; max-width: 100%; min-width: 0; }
        }
        @media (max-width: 390px) {
            .addons-page .row .button,
            .addons-page .stack .button { min-height: 44px; white-space: normal; }
            .addons-page .row .grow { flex-basis: 100%; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Add-ons</h1>
            <p class="lede">Optional extras and equipment guests can add when they book. The price stored here is what the booking uses. Tax, the booking fee, and refunds stay on the rules already saved.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
        @if ($problem)<p class="error" role="alert">{{ $problem }}</p>@endif
    </div>

    <div class="split">
        <section class="panel">
            <h2>Catalog</h2>
            @if ($addOns->isEmpty())
                <p class="empty">No add-ons yet.</p>
            @else
                <ul class="rows">
                    @foreach ($addOns as $addOn)
                        <li class="row" wire:key="addon-{{ $addOn->id }}">
                            <div class="grow">
                                <strong>{{ $addOn->name }}</strong>
                                <span class="muted">{{ $kinds[$addOn->kind->value] ?? $addOn->kind->value }} · {{ $prices[$addOn->pricing->value] ?? $addOn->pricing->value }} · {{ Display::money($addOn->amount_cents) }}@if ($addOn->pricing->value === 'each' && $addOn->max_quantity) · up to {{ $addOn->max_quantity }}@endif</span>
                                @if ($addOn->description)
                                    <span class="muted">{{ $addOn->description }}</span>
                                @endif
                            </div>
                            @if (! $addOn->active)
                                <span class="badge">Off</span>
                            @endif
                            @if ($canManage)
                                <button type="button" class="button small" wire:click="edit('{{ $addOn->id }}')">Edit</button>
                                @if ($addOn->active)
                                    <button type="button" class="button small" wire:click="deactivate('{{ $addOn->id }}')">Turn off</button>
                                @endif
                                <button type="button" class="button small" wire:click="delete('{{ $addOn->id }}')">Delete</button>
                            @elseif ($addOn->active)
                                <span class="muted">On</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($canManage)
            <section class="panel">
                <h2>{{ $editingId === '' ? 'Add an extra' : 'Edit '.$name }}</h2>
                <form wire:submit="{{ $editingId === '' ? 'create' : 'save' }}" class="stack">
                    <label class="field"><span>Name</span><input type="text" wire:model="name" maxlength="120" required autocomplete="off" placeholder="Dry bag"></label>
                    <label class="field"><span>Description <span class="muted">(optional)</span></span><textarea wire:model="description" maxlength="500" rows="3"></textarea></label>
                    <label class="field"><span>Kind</span>
                        <select wire:model="kind">
                            @foreach ($kinds as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field"><span>Charged</span>
                        <select wire:model.live="pricing">
                            @foreach ($prices as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field"><span>Price ($)</span><input type="text" inputmode="decimal" wire:model="amount" required placeholder="2.00"></label>
                    @if ($pricing === 'each')
                        <label class="field"><span>Maximum quantity <span class="muted">(optional)</span></span><input type="number" min="1" max="100" step="1" wire:model="maxQuantity" placeholder="No limit"></label>
                    @endif
                    @if ($editingId !== '')
                        <label class="check"><input type="checkbox" wire:model="active"><span>Active</span></label>
                    @endif
                    @error('name')<p class="error">{{ $message }}</p>@enderror
                    @error('description')<p class="error">{{ $message }}</p>@enderror
                    @error('kind')<p class="error">{{ $message }}</p>@enderror
                    @error('pricing')<p class="error">{{ $message }}</p>@enderror
                    @error('amount')<p class="error">{{ $message }}</p>@enderror
                    @error('maxQuantity')<p class="error">{{ $message }}</p>@enderror
                    <button type="submit" class="button primary">{{ $editingId === '' ? 'Add an extra' : 'Save' }}</button>
                    @if ($editingId !== '')
                        <button type="button" class="button" wire:click="cancel">Cancel</button>
                    @endif
                </form>
            </section>
        @endif
    </div>
</div>
