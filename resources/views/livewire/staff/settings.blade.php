@php use App\Support\Display; @endphp
<div class="page narrow">
    <header class="page-head">
        <h1>Settings</h1>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
        @if ($problem)<p class="flash bad" role="alert">{{ $problem }}</p>@endif
    </div>

    <section class="panel">
        <h2>Booking fee</h2>
        <p>
            ReserveWorx charges a booking fee of {{ rtrim(rtrim(number_format($directRate->basisPoints / 100, 2), '0'), '.') }}%
            on each online card payment. There is no fee on payments you record as paid offline.
        </p>
        <form wire:submit="save" class="stack">
            <fieldset class="choice">
                <legend>Who pays the booking fee</legend>
                <label class="check">
                    <input type="radio" wire:model="feePayer" value="customer_paid" @disabled(! $canUpdate)>
                    <span><strong>The customer.</strong> It shows as a separate “Booking fee” line and is included in every price customers see.</span>
                </label>
                <label class="check">
                    <input type="radio" wire:model="feePayer" value="operator_absorbed" @disabled(! $canUpdate)>
                    <span><strong>Your business.</strong> Customers see no fee. It comes out of your payout.</span>
                </label>
            </fieldset>
            <label class="field compact">
                <span>Hold boats during checkout for (minutes)</span>
                <input type="number" min="5" max="120" wire:model="holdMinutes" @disabled(! $canUpdate)>
            </label>
            @error('holdMinutes')<p class="error">{{ $message }}</p>@enderror
            @if ($canUpdate)<button type="submit" class="button primary">Save settings</button>@endif
        </form>
    </section>

    <section class="panel">
        <h2>Card payments</h2>
        @if ($account && $account->canCharge())
            <p>Connected to Stripe. Customers can pay by card when they book.</p>
        @elseif ($account)
            <p>Stripe account created, but Stripe has not turned on card payments yet. Finish Stripe’s setup to start taking cards.</p>
        @else
            <p>Connect a Stripe account so customers can pay by card. Your business stays the merchant; payouts go straight to you.</p>
        @endif
        @if ($canUpdate && ! ($account && $account->canCharge()))
            <form wire:submit="connectStripe" class="stack top-gap">
                @unless ($account)
                    <div class="form-row">
                        <label class="field compact grow"><span>Business name</span><input type="text" wire:model="stripeName" maxlength="120" required></label>
                        <label class="field compact grow"><span>Email</span><input type="email" wire:model="stripeEmail" maxlength="255" required></label>
                    </div>
                @endunless
                <button type="submit" class="button primary">{{ $account ? 'Finish Stripe setup' : 'Connect Stripe' }}</button>
            </form>
        @endif
    </section>
</div>
