@php
    $tenantModel = app(\App\Tenancy\TenantManager::class)->get();
    $staffUser = auth()->user();
    $nav = [
        ['href' => '/staff', 'label' => 'Today', 'match' => 'staff'],
        ['href' => '/staff/calendar', 'label' => 'Calendar', 'match' => 'staff/calendar'],
        ['href' => '/staff/bookings', 'label' => 'Bookings', 'match' => 'staff/bookings*'],
        ['href' => '/staff/bookings/new', 'label' => 'New booking', 'match' => 'staff/bookings/new'],
    ];
    $setup = [
        ['href' => '/staff/setup/locations', 'label' => 'Locations', 'match' => 'staff/setup/locations*'],
        ['href' => '/staff/setup/resources', 'label' => 'Boats & gear', 'match' => 'staff/setup/resources*'],
        ['href' => '/staff/setup/services', 'label' => 'Services & prices', 'match' => 'staff/setup/services*'],
        ['href' => '/staff/booking-links', 'label' => 'Booking links', 'match' => 'staff/booking-links'],
        ['href' => '/staff/settings', 'label' => 'Settings', 'match' => 'staff/settings'],
    ];
    $isActive = function (array $item): bool {
        if ($item['match'] === 'staff/bookings*') {
            return request()->is('staff/bookings') || (request()->is('staff/bookings/*') && ! request()->is('staff/bookings/new'));
        }

        return request()->is($item['match']);
    };
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>{{ isset($title) ? $title.' – ' : '' }}{{ $tenantModel?->name ?? 'ReserveWorx' }}</title>
</head>
<body class="staff">
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="staff-shell">
        <aside class="rail" aria-label="Workspace">
            <div class="rail-brand">
                <span class="rail-tenant">{{ $tenantModel?->name }}</span>
                <span class="rail-product">ReserveWorx</span>
            </div>
            <nav class="rail-nav" aria-label="Main">
                @foreach ($nav as $item)
                    <a href="{{ $item['href'] }}" wire:navigate @class(['is-active' => $isActive($item)]) @if($isActive($item)) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
                <p class="rail-group">Setup</p>
                @foreach ($setup as $item)
                    <a href="{{ $item['href'] }}" wire:navigate @class(['is-active' => $isActive($item)]) @if($isActive($item)) aria-current="page" @endif>{{ $item['label'] }}</a>
                @endforeach
            </nav>
            <div class="rail-user">
                <span>{{ $staffUser?->name }}</span>
                <form method="POST" action="/staff/logout">
                    @csrf
                    <button type="submit" class="link-button">Sign out</button>
                </form>
            </div>
        </aside>
        <main id="main" class="staff-main" tabindex="-1">
            {{ $slot }}
        </main>
    </div>
    @livewireScripts
</body>
</html>
