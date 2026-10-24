@php
    use App\Enums\BookingStatus;
    use App\Models\Booking;
    use App\Models\Location;

    $tenantModel = app(\App\Tenancy\TenantManager::class)->get();
    $staffUser = auth()->user();
    $brandName = ($workspaceBranding->displayName ?? '') !== '' ? $workspaceBranding->displayName : ($tenantModel?->name ?? 'ReserveWorx');
    $logo = $workspaceBranding->logoUrl ?? null;
    $locationNames = $tenantModel ? Location::query()->orderBy('name')->pluck('name') : collect();
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
        ['href' => '/staff/share', 'label' => 'Share', 'icon' => 'share', 'active' => request()->is('staff/share')],
        ['href' => '/staff/menu', 'label' => 'Menu', 'icon' => 'apps', 'active' => request()->is('staff/menu', 'staff/setup/*', 'staff/settings', 'staff/branding')],
    ];
    $setup = [
        ['href' => '/staff/setup/locations', 'label' => 'Locations', 'icon' => 'pin', 'active' => request()->is('staff/setup/locations*')],
        ['href' => '/staff/setup/resources', 'label' => 'Boats & gear', 'icon' => 'boat', 'active' => request()->is('staff/setup/resources*')],
        ['href' => '/staff/setup/services', 'label' => 'Services & prices', 'icon' => 'tag', 'active' => request()->is('staff/setup/services*')],
        ['href' => '/staff/branding', 'label' => 'Branding', 'icon' => 'palette', 'active' => request()->is('staff/branding')],
        ['href' => '/staff/settings', 'label' => 'Settings', 'icon' => 'settings', 'active' => request()->is('staff/settings')],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>{{ isset($title) ? $title.' – ' : '' }}{{ $brandName }}</title>
    <script src="/embed.js" defer data-navigate-once></script>
</head>
<body class="app">
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
                <p class="side-group">Setup</p>
                @foreach ($setup as $item)
                    <a href="{{ $item['href'] }}" wire:navigate @class(['is-active' => $item['active']]) @if($item['active']) aria-current="page" @endif>
                        <x-icon :name="$item['icon']" size="20" /> {{ $item['label'] }}
                    </a>
                @endforeach
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
                <a href="{{ $tab['href'] }}" wire:navigate @class(['is-active' => $tab['active']]) @if($tab['active']) aria-current="page" @endif>
                    <span class="tab-icon"><x-icon :name="$tab['icon']" size="24" /></span>
                    <span class="tab-label">{{ $tab['label'] }}</span>
                </a>
            @endforeach
        </nav>
    </div>
    @livewireScripts
</body>
</html>
