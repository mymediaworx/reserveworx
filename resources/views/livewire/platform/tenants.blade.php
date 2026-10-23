@php use App\Support\Display; @endphp
<div class="page">
    <header class="page-head">
        <div>
            <h1>Workspaces</h1>
            <p class="lede">{{ $tenants->total() }} {{ $tenants->total() === 1 ? 'operator' : 'operators' }} on ReserveWorx.</p>
        </div>
    </header>

    <div class="split">
        <section class="panel">
            <form class="filters" wire:submit.prevent>
                <label class="field compact grow">
                    <span>Search</span>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or slug">
                </label>
            </form>
            @if ($tenants->isEmpty())
                <p class="empty">No workspaces match.</p>
            @else
                <ul class="rows">
                    @foreach ($tenants as $tenant)
                        @php $domain = $primaryDomains->get($tenant->id); @endphp
                        <li class="row">
                            <a class="grow" href="/platform/tenants/{{ $tenant->id }}" wire:navigate>
                                <strong>{{ $tenant->name }}</strong>
                                <span class="muted">{{ $domain?->hostname ?? 'No domain yet' }}</span>
                            </a>
                            <span @class(['badge', 'badge-confirmed' => $tenant->status->value === 'active', 'badge-held' => $tenant->status->value === 'onboarding', 'badge-cancelled' => in_array($tenant->status->value, ['suspended', 'terminated'], true)])>{{ Display::status($tenant->status->value) }}</span>
                        </li>
                    @endforeach
                </ul>
                {{ $tenants->links('partials.pager') }}
            @endif
        </section>

        <section class="panel">
            <h2>Add a workspace</h2>
            <form wire:submit="create" class="stack">
                <label class="field"><span>Business name</span><input type="text" wire:model.live.debounce.300ms="name" maxlength="120" required placeholder="Lakeside Paddle Co"></label>
                <label class="field"><span>Short name (slug)</span><input type="text" wire:model="slug" maxlength="63" required pattern="[a-z0-9]+(-[a-z0-9]+)*"></label>
                @error('name')<p class="error">{{ $message }}</p>@enderror
                @error('slug')<p class="error">{{ $message }}</p>@enderror
                <button type="submit" class="button primary">Add workspace</button>
            </form>
            <p class="muted small top-gap">New workspaces start in onboarding with the standard 3% and 1% booking fees. Add a domain and an owner next.</p>
        </section>
    </div>
</div>
