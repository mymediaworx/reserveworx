@php use App\Support\Display; @endphp
<div class="page promo-page">
    <header class="page-head">
        <div>
            <h1>Promo codes</h1>
            <p class="lede">A percent or a fixed amount off the service total. The booking fee is calculated after the discount.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    <div class="split">
        <section class="panel">
            <h2>Codes</h2>
            @if ($promos->isEmpty())
                <p class="empty">No promo codes yet.</p>
            @else
                <ul class="rows">
                    @foreach ($promos as $promo)
                        <li class="row">
                            <div class="grow">
                                <strong><code>{{ $promo->code }}</code></strong>
                                <span class="muted">
                                    @if ($promo->percent_off !== null)
                                        {{ $promo->percent_off }}% off
                                    @endif
                                    @if ($promo->percent_off !== null && $promo->amount_off_cents !== null)
                                        and
                                    @endif
                                    @if ($promo->amount_off_cents !== null)
                                        {{ Display::money((int) $promo->amount_off_cents) }} off
                                    @endif
                                </span>
                                <span class="muted">{{ $promo->starts_on?->format('M j, Y') ?? 'Any start' }} – {{ $promo->ends_on?->format('M j, Y') ?? 'no end' }}</span>
                                <span class="muted">{{ $promo->redeemed_count }} used{{ $promo->usage_limit === null ? '' : ' of '.$promo->usage_limit }}</span>
                            </div>
                            @if (! $promo->active)
                                <span class="badge">Off</span>
                            @endif
                            @if ($canManage)
                                <button type="button" class="button small" wire:click="edit('{{ $promo->id }}')">Edit</button>
                                @if ($promo->active)
                                    <button type="button" class="button small" wire:click="deactivate('{{ $promo->id }}')">Deactivate</button>
                                @endif
                            @elseif ($promo->active)
                                <span class="muted">On</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        @if ($canManage)
            <section class="panel">
                <h2>{{ $editingId === '' ? 'Add a promo code' : 'Edit '.$code }}</h2>
                <form wire:submit="{{ $editingId === '' ? 'create' : 'save' }}" class="stack">
                    <label class="field"><span>Code</span><input type="text" wire:model="code" maxlength="40" required autocomplete="off" placeholder="SUMMER"></label>
                    <label class="field"><span>Discount</span>
                        <select wire:model.live="discountType">
                            <option value="percent">Percent off</option>
                            <option value="amount">Dollars off</option>
                        </select>
                    </label>
                    <label class="field"><span>{{ $discountType === 'percent' ? 'Percent' : 'Dollars' }}</span><input type="number" min="0.01" step="0.01" wire:model="discountValue" required></label>
                    <label class="field"><span>Valid from</span><input type="date" wire:model="startsOn"></label>
                    <label class="field"><span>Valid through</span><input type="date" wire:model="endsOn"></label>
                    <label class="field"><span>Use limit</span><input type="number" min="1" step="1" wire:model="usageLimit" placeholder="No limit"></label>
                    @if ($editingId !== '')
                        <label class="check"><input type="checkbox" wire:model="active"><span>Active</span></label>
                    @endif
                    @error('code')<p class="error">{{ $message }}</p>@enderror
                    @error('discountValue')<p class="error">{{ $message }}</p>@enderror
                    @error('startsOn')<p class="error">{{ $message }}</p>@enderror
                    @error('endsOn')<p class="error">{{ $message }}</p>@enderror
                    @error('usageLimit')<p class="error">{{ $message }}</p>@enderror
                    <button type="submit" class="button primary">{{ $editingId === '' ? 'Add a promo code' : 'Save' }}</button>
                    @if ($editingId !== '')
                        <button type="button" class="button" wire:click="cancel">Cancel</button>
                    @endif
                </form>
            </section>
        @endif
    </div>
</div>
