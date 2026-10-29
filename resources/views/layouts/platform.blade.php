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
                <a href="/platform/fees" wire:navigate @class(['is-active' => request()->is('platform/fees')]) @if(request()->is('platform/fees')) aria-current="page" @endif><x-icon name="dollar" size="20" /> Fees</a>
                <a href="/platform/help" wire:navigate @class(['is-active' => request()->is('platform/help')]) @if(request()->is('platform/help')) aria-current="page" @endif><x-icon name="book" size="20" /> Handbook</a>
                <a href="/platform/administrators" wire:navigate @class(['is-active' => request()->is('platform/administrators')]) @if(request()->is('platform/administrators')) aria-current="page" @endif><x-icon name="users" size="20" /> Administrators</a>
                <a href="/platform/readiness" wire:navigate @class(['is-active' => request()->is('platform/readiness')])><x-icon name="shield" size="20" /> Readiness</a>
                <a href="/platform/performance" wire:navigate @class(['is-active' => request()->is('platform/performance')]) @if(request()->is('platform/performance')) aria-current="page" @endif><x-icon name="clock" size="20" /> Performance</a>
                <a href="/platform/limits" wire:navigate @class(['is-active' => request()->is('platform/limits')]) @if(request()->is('platform/limits')) aria-current="page" @endif><x-icon name="window" size="20" /> Limits</a>
                <a href="/platform/security" wire:navigate @class(['is-active' => request()->is('platform/security')]) @if(request()->is('platform/security')) aria-current="page" @endif><x-icon name="lock" size="20" /> Security</a>
                <a href="/platform/isolation" wire:navigate @class(['is-active' => request()->is('platform/isolation')]) @if(request()->is('platform/isolation')) aria-current="page" @endif><x-icon name="shield" size="20" /> Isolation</a>
                <a href="/platform/upgrade" wire:navigate @class(['is-active' => request()->is('platform/upgrade')]) @if(request()->is('platform/upgrade')) aria-current="page" @endif><x-icon name="refresh" size="20" /> Upgrade</a>
                <a href="/platform/demo" wire:navigate @class(['is-active' => request()->is('platform/demo')]) @if(request()->is('platform/demo')) aria-current="page" @endif><x-icon name="apps" size="20" /> Demo</a>
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
