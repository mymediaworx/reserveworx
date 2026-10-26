@php use App\Support\Display; @endphp
<div class="page">
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Workspaces</h1>
            <p class="lede">
                @if ($filtering)
                    {{ $tenants->total() }} of {{ $operatorCount }} {{ $operatorCount === 1 ? 'operator' : 'operators' }} match.
                @else
                    {{ $tenants->total() }} {{ $tenants->total() === 1 ? 'operator' : 'operators' }} on ReserveWorx.
                @endif
            </p>
        </div>
    </header>

    @if ($schedulerStatus !== 'ok')
        <section class="notice" role="alert" data-scheduler="{{ $schedulerStatus }}">
            <p><strong>Scheduled jobs are not running.</strong>
                @if ($schedulerLastRun)
                    The scheduler last ran {{ $schedulerLastRun->diffForHumans() }}.
                @else
                    The scheduler has not run since this check was added.
                @endif
                Until it runs, staff cancellations do not finish or refund, imports wait, and reminders and overdue flags stop.
                Add one Site Tools cron for app.reserveworx.com, every minute: <code>php artisan schedule:run</code> in <code>laravel/current</code>.</p>
        </section>
    @endif

    <div class="stat-grid">
        @foreach ($statuses as $option)
            @php $count = $statusCounts[$option->value] ?? 0; @endphp
            <button type="button" wire:click="filterStatus('{{ $option->value }}')" @class(['stat-card', 'compact', 'is-selected' => $status === $option->value]) data-status="{{ $option->value }}" data-count="{{ $count }}">
                <span class="stat-strip">{{ Display::status($option->value) }}</span>
                <span class="stat-body">
                    <span class="stat-value">{{ $count }}</span>
                </span>
            </button>
        @endforeach
    </div>

    <section class="panel" aria-label="Industries">
        <h2>Industries</h2>
        <ul class="industry-counts">
            @foreach ($industries as $option)
                <li>
                    <button type="button" wire:click="filterIndustry('{{ $option->value }}')" @class(['is-selected' => $industryFilter === $option->value])>
                        {{ $option->label() }}
                        <span>{{ $industryCounts[$option->value] ?? 0 }}</span>
                    </button>
                </li>
            @endforeach
        </ul>
    </section>

    <div class="split">
        <section class="panel">
            <form class="filters" wire:submit.prevent>
                <label class="field compact grow">
                    <span>Search</span>
                    <input type="search" wire:model.live.debounce.300ms="search" placeholder="Name or slug">
                </label>
                <label class="field compact">
                    <span>Status</span>
                    <select wire:model.live="status">
                        <option value="">All statuses</option>
                        @foreach ($statuses as $option)
                            <option value="{{ $option->value }}">{{ Display::status($option->value) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field compact">
                    <span>Industry</span>
                    <select wire:model.live="industryFilter">
                        <option value="">All industries</option>
                        @include('partials.industry-options', ['selected' => $industryFilter])
                    </select>
                </label>
                @if ($filtering)
                    <button type="button" class="button" wire:click="clearFilters">Clear filters</button>
                @endif
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
                            <span class="muted">{{ $tenant->industry->label() }}</span>
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
                <label class="field">
                    <span>Industry</span>
                    <select wire:model="industry">
                        @include('partials.industry-options', ['selected' => $industry])
                    </select>
                </label>
                @error('name')<p class="error">{{ $message }}</p>@enderror
                @error('slug')<p class="error">{{ $message }}</p>@enderror
                @error('industry')<p class="error">{{ $message }}</p>@enderror
                <button type="submit" class="button primary">Add workspace</button>
            </form>
            <p class="muted small top-gap">New workspaces start in onboarding with the standard 3% and 1% booking fees. Add a domain and an owner next.</p>
        </section>
    </div>
</div>
