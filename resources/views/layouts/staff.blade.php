@php
    use App\Enums\BookingStatus;
    use App\Models\Booking;
    use App\Models\CsvImport;
    use App\Models\Location;
    use App\Models\User;
    use App\Tenancy\Access;
    use App\Tenancy\StaffLocationAccess;

    $tenantModel = app(\App\Tenancy\TenantManager::class)->get();
    $staffUser = auth()->user();
    $canManageTeam = $staffUser instanceof User
        && $tenantModel !== null
        && app(Access::class)->allows($staffUser, $tenantModel->id, 'members.manage');
    $canImport = $staffUser instanceof User && $staffUser->can('viewAny', CsvImport::class);
    $canPrivacy = $staffUser instanceof User
        && $tenantModel !== null
        && app(Access::class)->allows($staffUser, $tenantModel->id, 'customers.privacy')
        && ! app(StaffLocationAccess::class)->isRestricted($staffUser);
    $canAudit = $staffUser instanceof User
        && $tenantModel !== null
        && app(Access::class)->allows($staffUser, $tenantModel->id, 'audit.view')
        && ! app(StaffLocationAccess::class)->isRestricted($staffUser);
    $canCreateBooking = $staffUser instanceof User
        && $tenantModel !== null
        && app(Access::class)->allows($staffUser, $tenantModel->id, 'bookings.manage');
    $brandName = ($workspaceBranding->displayName ?? '') !== '' ? $workspaceBranding->displayName : ($tenantModel?->name ?? 'ReserveWorx');
    $logo = $workspaceBranding->logoUrl ?? null;
    $switchLocations = $tenantModel ? Location::query()->orderBy('name')->get(['id', 'name']) : collect();
    $locationNames = $switchLocations->pluck('name');
    $subtitle = match (true) {
        $locationNames->count() === 1 => $locationNames->first(),
        $locationNames->count() > 1 => $locationNames->count().' locations',
        default => 'Getting started',
    };
    $waiting = $tenantModel ? Booking::query()->where('status', BookingStatus::Held->value)->count() : 0;

    $tabs = [
        ['href' => '/staff', 'label' => 'Home', 'icon' => 'home', 'active' => request()->is('staff')],
        ['href' => '/staff/bookings', 'label' => 'Bookings', 'icon' => 'bookings', 'active' => request()->is('staff/bookings', 'staff/bookings/*') && ! request()->is('staff/bookings/new')],
        ['href' => '/staff/calendar', 'label' => 'Calendar', 'icon' => 'calendar', 'active' => request()->is('staff/calendar')],
        ['href' => '/staff/schedule', 'label' => 'Schedule', 'icon' => 'schedule', 'active' => request()->is('staff/schedule'), 'mobile' => false],
        ['href' => '/staff/share', 'label' => 'Share', 'icon' => 'share', 'active' => request()->is('staff/share')],
        ['href' => '/staff/menu', 'label' => 'Menu', 'icon' => 'apps', 'active' => request()->is('staff/menu', 'staff/help', 'staff/setup/*', 'staff/locations/*', 'staff/closures', 'staff/waivers', 'staff/promo-codes', 'staff/add-ons', 'staff/corporate-accounts', 'staff/import', 'staff/customers', 'staff/audit', 'staff/settings', 'staff/team', 'staff/branding', 'staff/feedback', 'staff/check-in', 'staff/check-in/*', 'staff/returns', 'staff/incidents', 'staff/incidents/*', 'staff/maintenance', 'staff/manifest', 'staff/load-sheet', 'staff/reports', 'staff/waitlist')],
    ];
    $setup = [
        ['href' => '/staff/setup/locations', 'label' => 'Locations', 'icon' => 'pin', 'active' => request()->is('staff/setup/locations*', 'staff/locations/*/tax')],
        ['href' => '/staff/closures', 'label' => 'Closures', 'icon' => 'closure', 'active' => request()->is('staff/closures')],
        ['href' => '/staff/waivers', 'label' => 'Waivers', 'icon' => 'shield', 'active' => request()->is('staff/waivers')],
        ['href' => '/staff/setup/resources', 'label' => $industry->catalog(), 'icon' => $industry->icon(), 'active' => request()->is('staff/setup/resources*')],
        ['href' => '/staff/setup/services', 'label' => 'Services & prices', 'icon' => 'tag', 'active' => request()->is('staff/setup/services*')],
        ['href' => '/staff/promo-codes', 'label' => 'Promo codes', 'icon' => 'tag', 'active' => request()->is('staff/promo-codes')],
        ['href' => '/staff/add-ons', 'label' => 'Add-ons', 'icon' => 'plus', 'active' => request()->is('staff/add-ons')],
        ['href' => '/staff/corporate-accounts', 'label' => 'Corporate accounts', 'icon' => 'users', 'active' => request()->is('staff/corporate-accounts')],
        ['href' => '/staff/branding', 'label' => 'Branding', 'icon' => 'palette', 'active' => request()->is('staff/branding')],
        ['href' => '/staff/settings', 'label' => 'Settings', 'icon' => 'settings', 'active' => request()->is('staff/settings')],
    ];
    if ($canImport) {
        $setup[] = ['href' => '/staff/import', 'label' => 'Import', 'icon' => 'upload', 'active' => request()->is('staff/import')];
    }
    if ($canPrivacy) {
        $setup[] = ['href' => '/staff/customers', 'label' => 'Customer records', 'icon' => 'users', 'active' => request()->is('staff/customers')];
    }
    if ($canAudit) {
        $setup[] = ['href' => '/staff/audit', 'label' => 'Audit log', 'icon' => 'history', 'active' => request()->is('staff/audit')];
    }
    if ($canManageTeam) {
        $setup[] = ['href' => '/staff/team', 'label' => 'Team', 'icon' => 'users', 'active' => request()->is('staff/team')];
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>{{ isset($title) ? $title.' – ' : '' }}{{ $brandName }}</title>
    <script src="/embed.js" defer data-navigate-once></script>
</head>
<body class="app" @if ($canCreateBooking) data-new-booking="1" @endif>
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="app-shell">
        <aside class="app-side" aria-label="Workspace">
            <a class="side-identity" href="/staff" wire:navigate>
                <x-avatar :name="$brandName" :logo="$logo" />
                <span class="identity-text">
                    <span class="identity-name">{{ $brandName }}</span>
                    <span class="identity-sub">{{ $subtitle }}</span>
                </span>
            </a>
            <a class="button primary block" href="/staff/bookings/new" wire:navigate><x-icon name="plus" size="18" /> New booking</a>
            <nav class="side-nav" aria-label="Main">
                @foreach ($tabs as $tab)
                    @continue($tab['href'] === '/staff/menu')
                    <a href="{{ $tab['href'] }}" wire:navigate @class(['is-active' => $tab['active']]) @if($tab['active']) aria-current="page" @endif>
                        <x-icon :name="$tab['icon']" size="20" /> {{ $tab['label'] === 'Share' ? 'Share & embed' : $tab['label'] }}
                    </a>
                @endforeach
                <a href="/staff/feedback" wire:navigate @class(['is-active' => request()->is('staff/feedback')]) @if(request()->is('staff/feedback')) aria-current="page" @endif>
                    <x-icon name="message" size="20" /> Feedback
                </a>
                <a href="/staff/check-in" wire:navigate @class(['is-active' => request()->is('staff/check-in')]) @if(request()->is('staff/check-in')) aria-current="page" @endif>
                    <x-icon name="check" size="20" /> Check in
                </a>
                <a href="/staff/returns" wire:navigate @class(['is-active' => request()->is('staff/returns')]) @if(request()->is('staff/returns')) aria-current="page" @endif>
                    <x-icon name="return" size="20" /> Returns
                </a>
                <a href="/staff/incidents" wire:navigate @class(['is-active' => request()->is('staff/incidents', 'staff/incidents/*')]) @if(request()->is('staff/incidents', 'staff/incidents/*')) aria-current="page" @endif>
                    <x-icon name="alert" size="20" /> Incidents
                </a>
                <a href="/staff/maintenance" wire:navigate @class(['is-active' => request()->is('staff/maintenance')]) @if(request()->is('staff/maintenance')) aria-current="page" @endif>
                    <x-icon name="wrench" size="20" /> Maintenance
                </a>
                <a href="/staff/manifest" wire:navigate @class(['is-active' => request()->is('staff/manifest')]) @if(request()->is('staff/manifest')) aria-current="page" @endif>
                    <x-icon name="manifest" size="20" /> Manifest
                </a>
                <a href="/staff/load-sheet" wire:navigate @class(['is-active' => request()->is('staff/load-sheet')]) @if(request()->is('staff/load-sheet')) aria-current="page" @endif>
                    <x-icon name="truck" size="20" /> Load sheet
                </a>
                <a href="/staff/reports" wire:navigate @class(['is-active' => request()->is('staff/reports')]) @if(request()->is('staff/reports')) aria-current="page" @endif>
                    <x-icon name="trending" size="20" /> Reports
                </a>
                <a href="/staff/waitlist" wire:navigate @class(['is-active' => request()->is('staff/waitlist')]) @if(request()->is('staff/waitlist')) aria-current="page" @endif>
                    <x-icon name="clock" size="20" /> Waitlist
                </a>
                <a href="/staff/help" wire:navigate @class(['is-active' => request()->is('staff/help')]) @if(request()->is('staff/help')) aria-current="page" @endif>
                    <x-icon name="book" size="20" /> Handbook
                </a>
                <p class="side-group">Setup</p>
                @foreach ($setup as $item)
                    <a href="{{ $item['href'] }}" wire:navigate @class(['is-active' => $item['active']]) @if($item['active']) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" size="20" /> {{ $item['label'] }}
                    </a>
                @endforeach
                @include('layouts.partials.business-switcher', ['variant' => 'nav'])
            </nav>
            <div class="side-user">
                <span>{{ $staffUser?->name }}</span>
                <form method="POST" action="/staff/logout">
                    @csrf
                    <button type="submit" class="link-button">Sign out</button>
                </form>
            </div>
        </aside>

        <div class="app-main">
            <header class="app-bar">
                <a class="app-identity" href="/staff" wire:navigate>
                    <x-avatar :name="$brandName" :logo="$logo" />
                    <span class="identity-text">
                        <span class="identity-name">{{ $brandName }}</span>
                        <span class="identity-sub">{{ $subtitle }}</span>
                    </span>
                </a>
                <div class="app-bar-actions">
                    <button type="button" class="icon-button" data-staff-search aria-label="Search" aria-keyshortcuts="Control+K Meta+K">
                        <x-icon name="search" size="26" />
                    </button>
                    <a class="icon-button" href="/staff/bookings?status=held" wire:navigate aria-label="{{ $waiting }} {{ $waiting === 1 ? 'booking' : 'bookings' }} waiting on payment">
                        <x-icon name="bell" size="26" />
                        @if ($waiting > 0)
                            <span class="dot-count" aria-hidden="true">{{ $waiting > 9 ? '9+' : $waiting }}</span>
                        @endif
                    </a>
                    <a class="icon-button" href="/staff/settings" wire:navigate aria-label="Settings">
                        <x-icon name="settings" size="26" />
                    </a>
                </div>
            </header>
            @if ($switchLocations->isNotEmpty())
                <nav class="location-switcher" aria-label="Locations">
                    <a href="/staff/calendar" @class(['is-current' => request()->is('staff/calendar') && request('location', '') === ''])>All locations</a>
                    @foreach ($switchLocations as $switchLocation)
                        <a href="/staff/calendar?location={{ $switchLocation->id }}" @class(['is-current' => request('location') === $switchLocation->id])>{{ $switchLocation->name }}</a>
                    @endforeach
                </nav>
            @endif
            <main id="main" class="app-content" tabindex="-1">
                {{ $slot }}
            </main>
        </div>

        @unless (request()->is('staff/bookings/new'))
            <a class="fab" href="/staff/bookings/new" wire:navigate aria-label="New booking">
                <x-icon name="plus" size="28" />
            </a>
        @endunless

        <nav class="tab-bar" aria-label="Main">
            @foreach ($tabs as $tab)
                @continue(($tab['mobile'] ?? true) === false)
                <a href="{{ $tab['href'] }}" wire:navigate @class(['is-active' => $tab['active']]) @if($tab['active']) aria-current="page" @endif>
                    <span class="tab-icon"><x-icon :name="$tab['icon']" size="24" /></span>
                    <span class="tab-label">{{ $tab['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>
    <livewire:staff.quick-search />
    @livewireScripts
    <script data-navigate-once>
        (function () {
            if (window.__staffShortcuts) {
                return;
            }
            window.__staffShortcuts = true;
            var pending = null;
            var timer = null;

            function typing(target) {
                if (!target || !target.tagName) {
                    return false;
                }
                if (target.isContentEditable) {
                    return true;
                }
                var tag = target.tagName;
                return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
            }

            document.addEventListener('click', function (event) {
                var button = event.target && event.target.closest ? event.target.closest('[data-staff-search]') : null;
                if (!button || !window.Livewire) {
                    return;
                }
                window.Livewire.dispatch('staff-search-toggle');
            });

            document.addEventListener('keydown', function (event) {
                var key = event.key;
                if (event.metaKey || event.ctrlKey) {
                    if (key.toLowerCase() === 'k' && window.Livewire) {
                        event.preventDefault();
                        window.Livewire.dispatch('staff-search-toggle');
                    }
                    return;
                }
                if (event.altKey || event.repeat || typing(event.target) || document.querySelector('[data-staff-overlay]')) {
                    pending = null;
                    return;
                }
                if (key === '?') {
                    event.preventDefault();
                    if (window.Livewire) {
                        window.Livewire.dispatch('staff-shortcuts-help');
                    }
                    return;
                }
                if (key === 'n' && document.body.dataset.newBooking === '1') {
                    event.preventDefault();
                    if (window.Livewire && window.Livewire.navigate) {
                        window.Livewire.navigate('/staff/bookings/new');
                    }
                    return;
                }
                if (pending === 'g' && (key === 'b' || key === 'd')) {
                    event.preventDefault();
                    pending = null;
                    if (window.Livewire && window.Livewire.navigate) {
                        window.Livewire.navigate(key === 'b' ? '/staff/bookings' : '/staff');
                    }
                    return;
                }
                if (key === 'g') {
                    pending = 'g';
                    window.clearTimeout(timer);
                    timer = window.setTimeout(function () { pending = null; }, 800);
                    return;
                }
                pending = null;
            });
        })();
    </script>
</body>
</html>
