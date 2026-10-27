@php use App\Support\Display; @endphp
<div class="page narrow settings-page">
    <style>
        @media (max-width: 390px) {
            .settings-page, .settings-page * { min-width: 0; }
            .settings-page h1,
            .settings-page h2,
            .settings-page p,
            .settings-page label,
            .settings-page .check { overflow-wrap: anywhere; }
            .settings-page .form-row { display: grid; grid-template-columns: minmax(0, 1fr); }
            .settings-page input,
            .settings-page button,
            .settings-page select { max-width: 100%; }
        }
    </style>
    <header class="page-head">
        <h1>Settings</h1>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
        @if ($problem)<p class="flash bad" role="alert">{{ $problem }}</p>@endif
    </div>

    <section class="panel">
        <h2>Business time zone</h2>
        <p>Booking pages, the guest manage page, the schedule, the load sheet, and emails use a location’s time zone when that location has one. Otherwise they use this zone.</p>
        <form wire:submit="saveTimezone" class="stack">
            <label class="field compact">
                <span>Time zone</span>
                <select wire:model="timezone" @disabled(! $canUpdate)>
                    @foreach ($timezones as $zone)
                        <option value="{{ $zone }}">{{ $zone }}</option>
                    @endforeach
                </select>
            </label>
            @error('timezone')<p class="error">{{ $message }}</p>@enderror
            @if ($canUpdate)<button type="submit" class="button primary">Save time zone</button>@endif
        </form>
    </section>

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
                <span>{{ $industry->holdMinutesLabel() }}</span>
                <input type="number" min="5" max="120" wire:model="holdMinutes" @disabled(! $canUpdate)>
            </label>
            @error('holdMinutes')<p class="error">{{ $message }}</p>@enderror
            @if ($canUpdate)<button type="submit" class="button primary">Save settings</button>@endif
        </form>
    </section>

    <section class="panel" id="capacity-ledger">
        <h2>Shared gear lock</h2>
        <p>Pools of interchangeable gear are locked in blocks of this many minutes. The usual length is 15. The next booking uses the length you save. A booking that is already held keeps the blocks it used. Saving does not change a price, a payment, a tax, a fee, or a refund.</p>
        <form wire:submit="saveLedgerBucket" class="stack">
            <label class="field compact">
                <span>Block length</span>
                <select wire:model="ledgerBucketMinutes" @disabled(! $canUpdate)>
                    @foreach ($ledgerBucketChoices as $minutes)
                        <option value="{{ $minutes }}">{{ $minutes }} minutes</option>
                    @endforeach
                </select>
            </label>
            @error('ledgerBucketMinutes')<p class="error">{{ $message }}</p>@enderror
            @if ($canUpdate)<button type="submit" class="button primary">Save block length</button>@endif
        </form>
    </section>

    <section class="panel">
        <h2>Cancellation and refunds</h2>
        <p>These windows decide the card refund when a customer cancels, and the refund when a booking is marked as a no-show. Until you save a window, a customer cancellation does not refund a card.</p>
        <form wire:submit="saveCancellationPolicy" class="stack">
            @foreach ($cancelTiers as $index => $tier)
                <div class="form-row">
                    <label class="field compact grow">
                        <span>Minutes before start</span>
                        <input type="number" min="0" max="525600" wire:model="cancelTiers.{{ $index }}.lead_minutes" @disabled(! $canUpdate)>
                    </label>
                    <label class="field compact grow">
                        <span>Refund percent</span>
                        <input type="number" min="0" max="100" wire:model="cancelTiers.{{ $index }}.refund_percent" @disabled(! $canUpdate)>
                    </label>
                    @if ($canUpdate)
                        <button type="button" class="button" wire:click="removeCancelTier({{ $index }})">Remove</button>
                    @endif
                </div>
                @error('cancelTiers.'.$index.'.lead_minutes')<p class="error">{{ $message }}</p>@enderror
                @error('cancelTiers.'.$index.'.refund_percent')<p class="error">{{ $message }}</p>@enderror
            @endforeach
            @error('cancelTiers')<p class="error">{{ $message }}</p>@enderror
            @if ($canUpdate && count($cancelTiers) < 8)
                <button type="button" class="button" wire:click="addCancelTier">Add window</button>
            @endif
            <label class="field compact">
                <span>No-show refund percent</span>
                <input type="number" min="0" max="100" wire:model="noShowPercent" @disabled(! $canUpdate)>
            </label>
            @error('noShowPercent')<p class="error">{{ $message }}</p>@enderror
            @if ($canUpdate)<button type="submit" class="button primary">Save cancellation policy</button>@endif
        </form>
    </section>

    <section class="panel">
        <h2>Payment receipts</h2>
        <p>After a card or offline payment, email the customer the receipt already stored on that booking. This stays off until you turn it on.</p>
        <form wire:submit="savePaymentReceipt" class="stack">
            <label class="check">
                <input type="checkbox" wire:model="emailPaymentReceipt" @disabled(! $canUpdate)>
                <span>Email customers a payment receipt</span>
            </label>
            @if ($canUpdate)<button type="submit" class="button primary">Save payment receipts</button>@endif
        </form>
    </section>

    <section class="panel" id="reminders">
        <h2>Reminders</h2>
        <p>A confirmed booking is reminded once at each of these times before it starts. The usual times are 24 hours and 2 hours. Remove every time to turn reminders off. Saving does not change a price, a payment, or a refund, and it does not send a reminder that already went out.</p>
        @if ($remindersAreDefault)
            <p class="muted">These are the usual times. They are not saved until you save reminders.</p>
        @elseif ($reminderOffsets === [])
            <p class="muted">Reminders are off.</p>
        @endif
        <form wire:submit="saveReminders" class="stack">
            @foreach ($reminderOffsets as $index => $minutes)
                <div class="form-row">
                    <label class="field compact grow">
                        <span>Minutes before the start</span>
                        <input type="number" min="1" max="10080" inputmode="numeric" wire:model="reminderOffsets.{{ $index }}" @disabled(! $canManageReminders)>
                    </label>
                    @if ($reminderHints[$index] !== '')
                        <p class="muted">{{ $reminderHints[$index] }}</p>
                    @endif
                    @if ($canManageReminders)
                        <button type="button" class="button" wire:click="removeReminder({{ $index }})">Remove</button>
                    @endif
                </div>
                @error('reminderOffsets.'.$index)<p class="error">{{ $message }}</p>@enderror
            @endforeach
            @error('reminderOffsets')<p class="error">{{ $message }}</p>@enderror
            @if ($canManageReminders && count($reminderOffsets) < 8)
                <button type="button" class="button" wire:click="addReminder">Add a reminder</button>
            @endif
            @if ($canManageReminders)<button type="submit" class="button primary">Save reminders</button>@endif
        </form>
    </section>

    <section class="panel" id="customer-messages">
        <h2>Customer messages</h2>
        <p>These are the messages a customer can get for a booking. Turning one off stops that message. Feedback stays on the feedback page, and the payment receipt stays on the switch above. A price, a payment, and a refund stay as they were.</p>
        <form wire:submit="saveMessages" class="stack">
            @foreach ($messageChoices as $index => $choice)
                <label class="check">
                    <input type="checkbox" wire:model="messageEnabled.{{ $index }}" @disabled(! $canUpdateMessages)>
                    <span>{{ $choice['label'] }}</span>
                </label>
            @endforeach
            @error('messageEnabled')<p class="error">{{ $message }}</p>@enderror
            @error('messageEnabled.*')<p class="error">{{ $message }}</p>@enderror
            @if ($canUpdateMessages)<button type="submit" class="button primary">Save customer messages</button>@endif
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
    @if ($canSeeSetupGuide)
        <section class="panel" aria-labelledby="setup-guide-setting">
            <h2 id="setup-guide-setting">Setup guide</h2>
            <p>{{ $setupGuideHidden ? 'The setup guide is hidden from the home page.' : 'The setup guide shows on the home page until every step is done.' }} <a href="/staff/setup" wire:navigate>Open setup guide</a></p>
            <button type="button" class="button" wire:click="toggleSetupGuide" role="switch" aria-checked="{{ $setupGuideHidden ? 'false' : 'true' }}">{{ $setupGuideHidden ? 'Show setup guide' : 'Hide setup guide' }}</button>
        </section>
    @endif
</div>
