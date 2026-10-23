<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>{{ isset($title) ? $title.' – ' : '' }}ReserveWorx platform</title>
</head>
<body class="staff platform">
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="staff-shell">
        <aside class="rail" aria-label="Platform">
            <div class="rail-brand">
                <span class="rail-tenant">ReserveWorx</span>
                <span class="rail-product">Platform administration</span>
            </div>
            <nav class="rail-nav" aria-label="Main">
                <a href="/platform" wire:navigate @class(['is-active' => request()->is('platform', 'platform/tenants*')])>Workspaces</a>
            </nav>
            <div class="rail-user">
                <span>{{ auth()->user()?->name }}</span>
                <form method="POST" action="/platform/logout">
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
