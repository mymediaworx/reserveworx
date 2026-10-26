<div class="page tax-page">
    <header class="page-head">
        <div>
            <p class="crumb"><a href="/staff/setup/locations" wire:navigate>Locations</a></p>
            <h1>Tax · {{ $location->name }}</h1>
            <p class="lede">Named rates for this location. Each one can apply to rentals, add-ons, and required fees. No rates means no tax.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    <section class="panel" data-booking-fee-tax="{{ $bookingFeeTaxable ? 'on' : 'off' }}">
        <h2>Booking fee</h2>
        <fieldset class="tax-applies">
            <label class="check">
                <input type="checkbox" wire:model.live="bookingFeeTaxable">
                <span>Tax the booking fee</span>
            </label>
        </fieldset>
        <p class="muted small">Off until you turn it on. Each rate that applies to rentals then also applies to the customer booking fee. Required charges such as cleaning stay on their own rates. An operator-absorbed booking has no customer booking-fee line, so this adds no tax. Whether a jurisdiction taxes that fee is still open.</p>
    </section>

    <div class="split">
        <section class="panel">
            <h2>Rates</h2>
            @if ($rules->isEmpty())
                <p class="empty">No tax rates. This location does not charge tax.</p>
            @else
                <ul class="rows">
                    @foreach ($rules as $rule)
                        <li class="row">
                            <div class="grow">
                                <strong>{{ $rule->name }}</strong>
                                <span class="muted">{{ $rule->percentLabel() }}% · {{ $rule->appliesLabel() }}</span>
                            </div>
                            <div class="actions">
                                <button type="button" class="button small" wire:click="edit('{{ $rule->id }}')">Edit</button>
                                <button type="button" class="button small" wire:click="remove('{{ $rule->id }}')">Remove</button>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel">
            <h2>{{ $editingId === '' ? 'Add a tax rate' : 'Edit '.$name }}</h2>
            <form wire:submit="{{ $editingId === '' ? 'create' : 'save' }}" class="stack">
                <label class="field">
                    <span>Name</span>
                    <input type="text" wire:model="name" maxlength="80" required autocomplete="off" placeholder="State sales tax">
                </label>
                <label class="field">
                    <span>Percent</span>
                    <input type="text" inputmode="decimal" wire:model="percent" required autocomplete="off" placeholder="8.25" maxlength="6">
                </label>
                <fieldset class="tax-applies">
                    <legend>Applies to</legend>
                    <label class="check"><input type="checkbox" wire:model="appliesToRentals"><span>Rentals</span></label>
                    <label class="check"><input type="checkbox" wire:model="appliesToAddOns"><span>Add-ons</span></label>
                    <label class="check"><input type="checkbox" wire:model="appliesToFees"><span>Fees</span></label>
                </fieldset>
                <p class="muted small">Fees are required charges such as cleaning or fuel. The booking fee is taxed only when the switch above is on, and only by rates that apply to rentals.</p>
                @error('name')<p class="error">{{ $message }}</p>@enderror
                @error('percent')<p class="error">{{ $message }}</p>@enderror
                @error('appliesToRentals')<p class="error">{{ $message }}</p>@enderror
                <div class="actions">
                    <button type="submit" class="button primary">{{ $editingId === '' ? 'Add tax rate' : 'Save' }}</button>
                    @if ($editingId !== '')
                        <button type="button" class="button" wire:click="cancel">Cancel</button>
                    @endif
                </div>
            </form>
        </section>
    </div>
</div>
