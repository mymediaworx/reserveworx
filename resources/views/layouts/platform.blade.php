<!DOCTYPE html>
<html lang="en">
<head>
    @include('layouts.partials.head')
    <title>{{ isset($title) ? $title.' – ' : '' }}ReserveWorx platform</title>
</head>
<body class="app platform">
    <a class="skip-link" href="#main">Skip to content</a>
    <div class="app-shell">
        <aside class="app-side" aria-label="Platform">
            <a class="side-identity" href="/platform" wire:navigate>
                <x-avatar name="Reserve Worx" />
                <span class="identity-text">
                    <span class="identity-name">ReserveWorx</span>
                    <span class="identity-sub">Master admin</span>
                </span>
            </a>
            <nav class="side-nav" aria-label="Main">
                <a href="/platform" wire:navigate @class(['is-active' => request()->is('platform', 'platform/tenants*')])><x-icon name="building" size="20" /> Workspaces</a>
                <a href="/platform/help" wire:navigate @class(['is-active' => request()->is('platform/help')]) @if(request()->is('platform/help')) aria-current="page" @endif><x-icon name="book" size="20" /> Handbook</a>
                <a href="/platform/administrators" wire:navigate @class(['is-active' => request()->is('platform/administrators')]) @if(request()->is('platform/administrators')) aria-current="page" @endif><x-icon name="users" size="20" /> Administrators</a>
                <a href="/platform/readiness" wire:navigate @class(['is-active' => request()->is('platform/readiness')])><x-icon name="shield" size="20" /> Readiness</a>
                <a href="/platform/upgrade" wire:navigate @class(['is-active' => request()->is('platform/upgrade')]) @if(request()->is('platform/upgrade')) aria-current="page" @endif><x-icon name="refresh" size="20" /> Upgrade</a>
            </nav>
            <div class="side-user">
                <span>{{ auth()->user()?->name }}</span>
                <form method="POST" action="/platform/logout">
                    @csrf
                    <button type="submit" class="link-button">Sign out</button>
                </form>
            </div>
        </aside>
        <div class="app-main">
            <header class="app-bar">
                <a class="app-identity" href="/platform" wire:navigate>
                    <x-avatar name="Reserve Worx" />
                    <span class="identity-text">
                        <span class="identity-name">ReserveWorx</span>
                        <span class="identity-sub">Master admin</span>
                    </span>
                </a>
                <form method="POST" action="/platform/logout" class="app-bar-actions">
                    @csrf
                    <button type="submit" class="icon-button" aria-label="Sign out"><x-icon name="logout" size="24" /></button>
                </form>
            </header>
            <main id="main" class="app-content platform-content" tabindex="-1">
                {{ $slot }}
            </main>
        </div>
    </div>
    @livewireScripts
</body>
</html>
