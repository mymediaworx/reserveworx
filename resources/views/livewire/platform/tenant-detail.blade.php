@php use App\Support\Display; @endphp
<div class="page">
    <p class="crumb"><a href="/platform" wire:navigate>Workspaces</a></p>
    <header class="page-head">
        <div>
            <h1>{{ $tenant->name }}</h1>
            <p class="lede">{{ $tenant->slug }}, booking fee paid by {{ $setting->fee_payer->value === 'customer_paid' ? 'the customer' : 'the operator' }}</p>
        </div>
        <span @class(['badge', 'large', 'badge-confirmed' => $tenant->status->value === 'active', 'badge-held' => $tenant->status->value === 'onboarding', 'badge-cancelled' => in_array($tenant->status->value, ['suspended', 'terminated'], true)])>{{ Display::status($tenant->status->value) }}</span>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
        @if ($problem)<p class="flash bad" role="alert">{{ $problem }}</p>@endif
    </div>

    @if ($newPassword)
        <section class="panel new-link">
            <h2>Temporary password</h2>
            <p>Share this with the new owner privately. It is not shown again.</p>
            <input type="text" readonly value="{{ $newPassword }}" onclick="this.select()" aria-label="Temporary password">
        </section>
    @endif

    <div class="split even">
        <section class="panel">
            <h2>Domains</h2>
            @if ($domains->isEmpty())
                <p class="empty">No domains yet. The workspace has no address until one is added and verified.</p>
            @else
                <ul class="rows compact">
                    @foreach ($domains as $domain)
                        <li class="row">
                            <span class="grow"><strong>{{ $domain->hostname }}</strong> <span class="muted">{{ $domain->type }}{{ $domain->is_primary ? ', primary' : '' }}</span></span>
                            @if ($domain->verified_at)
                                <a href="https://{{ $domain->hostname }}/staff/login" target="_blank" rel="noopener" class="button small">Open</a>
                                <span class="badge badge-confirmed">Verified</span>
                            @else
                                <button type="button" class="button small" wire:click="verifyDomain('{{ $domain->id }}')" wire:confirm="Mark {{ $domain->hostname }} as verified? Only do this once its DNS and SSL are working.">Mark verified</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addDomain" class="stack top-gap">
                <div class="form-row">
                    <label class="field compact grow"><span>Hostname</span><input type="text" wire:model="hostname" maxlength="253" required placeholder="lakeside.reserveworx.com"></label>
                    <label class="field compact"><span>Type</span>
                        <select wire:model="domainType"><option value="subdomain">Our subdomain</option><option value="custom">Their own domain</option></select>
                    </label>
                </div>
                <label class="check"><input type="checkbox" wire:model="primary"><span>Make this the primary address</span></label>
                @error('hostname')<p class="error">{{ $message }}</p>@enderror
                <button type="submit" class="button">Add domain</button>
            </form>
        </section>

        <section class="panel">
            <h2>Owners and team</h2>
            @if ($members->isEmpty())
                <p class="empty">Nobody can sign in yet. Add an owner.</p>
            @else
                <ul class="rows compact">
                    @foreach ($members as $member)
                        <li class="row">
                            <span class="grow"><strong>{{ $member->user?->name }}</strong> <span class="muted">{{ $member->user?->email }}</span></span>
                            <span class="muted">{{ $member->role?->name }}{{ $member->status->value !== 'active' ? ', '.$member->status->value : '' }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            <form wire:submit="addOwner" class="stack top-gap">
                <div class="form-row">
                    <label class="field compact grow"><span>Owner name</span><input type="text" wire:model="ownerName" maxlength="120" required></label>
                    <label class="field compact grow"><span>Email</span><input type="email" wire:model="ownerEmail" maxlength="255" required></label>
                </div>
                @error('ownerEmail')<p class="error">{{ $message }}</p>@enderror
                <button type="submit" class="button">Add owner</button>
            </form>
        </section>

        <section class="panel">
            <h2>Booking fee rates</h2>
            <p class="muted small">Charged on online card payments only. Fixed amounts and minimums are off at $0 and blank.</p>
            <form wire:submit="saveFees" class="stack">
                <div class="form-row">
                    <label class="field compact"><span>Direct bookings (%)</span><input type="number" step="0.01" min="0" max="100" wire:model="directPercent" required></label>
                    <label class="field compact"><span>Fixed ($)</span><input type="number" step="0.01" min="0" wire:model="directFixed" required></label>
                    <label class="field compact"><span>Minimum ($)</span><input type="number" step="0.01" min="0" wire:model="directMinimum"></label>
                </div>
                <div class="form-row">
                    <label class="field compact"><span>Third-party and API (%)</span><input type="number" step="0.01" min="0" max="100" wire:model="thirdPartyPercent" required></label>
                    <label class="field compact"><span>Fixed ($)</span><input type="number" step="0.01" min="0" wire:model="thirdPartyFixed" required></label>
                    <label class="field compact"><span>Minimum ($)</span><input type="number" step="0.01" min="0" wire:model="thirdPartyMinimum"></label>
                </div>
                @foreach (['directPercent', 'thirdPartyPercent', 'directFixed', 'thirdPartyFixed', 'directMinimum', 'thirdPartyMinimum'] as $field)
                    @error($field)<p class="error">{{ $message }}</p>@enderror
                @endforeach
                <button type="submit" class="button primary">Save rates</button>
            </form>
        </section>

        <section class="panel">
            <h2>Data export</h2>
            <p class="muted small">A private copy of this workspace. Passwords are left out. Closing for good stays unavailable until a file is saved here. The file is a snapshot, so export again if you change data first.</p>
            <button type="button" class="button" wire:click="exportData">Export workspace data</button>
            @if ($exports->isEmpty())
                <p class="empty top-gap">No export yet.</p>
            @else
                <ul class="rows compact top-gap">
                    @foreach ($exports as $export)
                        <li class="row">
                            <a href="{{ route('platform.exports.download', ['tenant' => $tenant, 'export' => $export]) }}">Download {{ $export->completed_at->timezone('UTC')->format('Y-m-d H:i') }} UTC</a>
                            <span class="muted">{{ number_format($export->bytes) }} bytes</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <section class="panel">
            <h2>Status</h2>
            <p class="muted small">Onboarding and active workspaces are served on their domains. Suspended ones show an unavailable page.</p>
            <div class="actions">
                @foreach ($statuses as $status)
                    @continue($status === $tenant->status)
                    <button type="button" @class(['button', 'danger' => in_array($status->value, ['suspended', 'terminated'], true)]) wire:click="setStatus('{{ $status->value }}')" wire:confirm="Change {{ $tenant->name }} to {{ $status->value }}?">{{ ucfirst($status->value === 'active' ? 'activate' : ($status->value === 'suspended' ? 'suspend' : ($status->value === 'terminated' ? 'close for good' : 'back to onboarding'))) }}</button>
                @endforeach
            </div>
        </section>
    </div>
</div>
