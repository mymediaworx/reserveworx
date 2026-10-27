<div class="page narrow">
    <header class="page-head">
        <h1>Menu</h1>
    </header>

    <section class="panel">
        <div class="panel-head"><h2>Daily work</h2></div>
        <div class="app-grid">
            <a href="/staff/bookings/new" wire:navigate><span class="app-circle"><x-icon name="plus" size="26" /></span>New booking</a>
            <a href="/staff/bookings" wire:navigate><span class="app-circle"><x-icon name="bookings" size="26" /></span>Bookings</a>
            <a href="/staff/calendar" wire:navigate><span class="app-circle"><x-icon name="calendar" size="26" /></span>Calendar</a>
            <a href="/staff/schedule" wire:navigate><span class="app-circle"><x-icon name="schedule" size="26" /></span>Schedule</a>
            <a href="/staff/feedback" wire:navigate><span class="app-circle"><x-icon name="message" size="26" /></span>Feedback</a>
            <a href="/staff/check-in" wire:navigate><span class="app-circle"><x-icon name="check" size="26" /></span>Check in</a>
            <a href="/staff/returns" wire:navigate><span class="app-circle"><x-icon name="return" size="26" /></span>Returns</a>
            <a href="/staff/incidents" wire:navigate><span class="app-circle"><x-icon name="alert" size="26" /></span>Incidents</a>
            <a href="/staff/maintenance" wire:navigate><span class="app-circle"><x-icon name="wrench" size="26" /></span>Maintenance</a>
            <a href="/staff/manifest" wire:navigate><span class="app-circle"><x-icon name="manifest" size="26" /></span>Manifest</a>
            <a href="/staff/load-sheet" wire:navigate><span class="app-circle"><x-icon name="truck" size="26" /></span>Load sheet</a>
            <a href="/staff/reports" wire:navigate><span class="app-circle"><x-icon name="trending" size="26" /></span>Reports</a>
            <a href="/staff/waitlist" wire:navigate><span class="app-circle"><x-icon name="clock" size="26" /></span>Waitlist</a>
            <a href="/staff/share" wire:navigate><span class="app-circle"><x-icon name="share" size="26" /></span>Share & embed</a>
            <a href="/staff/help" wire:navigate><span class="app-circle"><x-icon name="book" size="26" /></span>Handbook</a>
        </div>
    </section>

    <section class="panel top-gap">
        <div class="panel-head"><h2>Set up your business</h2></div>
        <div class="app-grid">
            <a href="/staff/setup/locations" wire:navigate><span class="app-circle"><x-icon name="pin" size="26" /></span>Locations</a>
            <a href="/staff/closures" wire:navigate><span class="app-circle"><x-icon name="closure" size="26" /></span>Closures</a>
            <a href="/staff/waivers" wire:navigate><span class="app-circle"><x-icon name="shield" size="26" /></span>Waivers</a>
            <a href="/staff/setup/resources" wire:navigate><span class="app-circle"><x-icon :name="$industry->icon()" size="26" /></span>{{ $industry->catalog() }}</a>
            <a href="/staff/setup/services" wire:navigate><span class="app-circle"><x-icon name="tag" size="26" /></span>Services & prices</a>
            <a href="/staff/promo-codes" wire:navigate><span class="app-circle"><x-icon name="tag" size="26" /></span>Promo codes</a>
            <a href="/staff/add-ons" wire:navigate><span class="app-circle"><x-icon name="plus" size="26" /></span>Add-ons</a>
            <a href="/staff/corporate-accounts" wire:navigate><span class="app-circle"><x-icon name="users" size="26" /></span>Corporate accounts</a>
            @if ($canImport)
                <a href="/staff/import" wire:navigate><span class="app-circle"><x-icon name="upload" size="26" /></span>Import</a>
            @endif
            <a href="/staff/branding" wire:navigate><span class="app-circle"><x-icon name="palette" size="26" /></span>Branding</a>
        </div>
    </section>

    @include('layouts.partials.business-switcher', ['variant' => 'menu'])

    <section class="panel top-gap">
        <ul class="menu-list">
            @if ($canManageTeam)
                <li>
                    <a href="/staff/team" wire:navigate>
                        <span class="icon-circle tone-neutral"><x-icon name="users" size="20" /></span>
                        <span class="menu-text">Team<small>Invite people and set roles</small></span>
                        <x-icon name="chevron-right" size="20" />
                    </a>
                </li>
            @endif
            @if ($canPrivacy)
                <li>
                    <a href="/staff/customers" wire:navigate>
                        <span class="icon-circle tone-neutral"><x-icon name="shield" size="20" /></span>
                        <span class="menu-text">Customer records<small>Download a record or remove personal details</small></span>
                        <x-icon name="chevron-right" size="20" />
                    </a>
                </li>
            @endif
            @if ($canAudit)
                <li>
                    <a href="/staff/audit" wire:navigate>
                        <span class="icon-circle tone-neutral"><x-icon name="history" size="20" /></span>
                        <span class="menu-text">Audit log<small>Who changed what in this workspace</small></span>
                        <x-icon name="chevron-right" size="20" />
                    </a>
                </li>
            @endif
            <li>
                <a href="/staff/settings" wire:navigate>
                    <span class="icon-circle tone-neutral"><x-icon name="settings" size="20" /></span>
                    <span class="menu-text">Settings<small>Booking fee, holds, card payments</small></span>
                    <x-icon name="chevron-right" size="20" />
                </a>
            </li>
            <li>
                <form method="POST" action="/staff/logout">
                    @csrf
                    <button type="submit">
                        <span class="icon-circle tone-neutral"><x-icon name="logout" size="20" /></span>
                        <span class="menu-text">Sign out<small>{{ $user->email }}</small></span>
                    </button>
                </form>
            </li>
        </ul>
    </section>
</div>
