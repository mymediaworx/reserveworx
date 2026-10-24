@php
    $account = auth()->user();
    $here = app(\App\Tenancy\TenantManager::class)->get();
    $nav = ($account instanceof \App\Models\User && $here instanceof \App\Models\Tenant)
        ? app(\App\Services\Workspaces\WorkspaceDirectory::class)->navigation($account, $here)
        : null;
    $variant = $variant ?? 'nav';
@endphp
@if (is_array($nav) && ($nav['owner'] || $nav['targets'] !== []))
    @if ($variant === 'menu')
        <section class="panel top-gap">
            <div class="panel-head"><h2>Your businesses</h2></div>
            <ul class="menu-list">
                @if ($nav['owner'])
                    <li>
                        <a href="/staff/portfolio">
                            <span class="icon-circle tone-brand"><x-icon name="trending" size="20" /></span>
                            <span class="menu-text">All businesses<small>Totals across businesses you own</small></span>
                            <x-icon name="chevron-right" size="20" />
                        </a>
                    </li>
                    <li>
                        <a href="/staff/businesses/new">
                            <span class="icon-circle tone-neutral"><x-icon name="plus" size="20" /></span>
                            <span class="menu-text">Add a business<small>Same setup, standard booking fees</small></span>
                            <x-icon name="chevron-right" size="20" />
                        </a>
                    </li>
                @endif
                @foreach ($nav['targets'] as $target)
                    <li>
                        @if ($target['hostname'])
                            <form method="post" action="/staff/switch/{{ $target['id'] }}">
                                @csrf
                                <button type="submit">
                                    <span class="icon-circle tone-neutral"><x-icon name="external" size="20" /></span>
                                    <span class="menu-text">{{ $target['name'] }}<small>{{ $target['role'] }}</small></span>
                                </button>
                            </form>
                        @else
                            <span class="menu-text">{{ $target['name'] }}<small>Address not verified</small></span>
                        @endif
                    </li>
                @endforeach
            </ul>
            @error('switch')<p class="error" role="alert">{{ $message }}</p>@enderror
        </section>
    @else
        @if ($nav['owner'])
            <p class="side-group">Businesses</p>
            <a href="/staff/portfolio" @class(['is-active' => request()->is('staff/portfolio')]) @if(request()->is('staff/portfolio')) aria-current="page" @endif>
                <x-icon name="trending" size="20" /> All businesses
            </a>
            <a href="/staff/businesses/new" @class(['is-active' => request()->is('staff/businesses/new')])>
                <x-icon name="plus" size="20" /> Add a business
            </a>
        @endif
        @if ($nav['targets'] !== [])
            <p class="side-group">Switch</p>
            @foreach ($nav['targets'] as $target)
                @if ($target['hostname'])
                    <form method="post" action="/staff/switch/{{ $target['id'] }}">
                        @csrf
                        <button type="submit" class="nav-link">
                            <x-icon name="external" size="20" /> {{ $target['name'] }}
                        </button>
                    </form>
                @else
                    <span class="nav-link is-quiet">{{ $target['name'] }}</span>
                @endif
            @endforeach
            @error('switch')<p class="error">{{ $message }}</p>@enderror
        @endif
    @endif
@endif
