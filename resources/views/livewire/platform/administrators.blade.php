<div class="page administrators-page">
    <style>
        @media (max-width: 390px) {
            .administrators-page { min-width: 0; max-width: 100%; }
            .administrators-page .page-head,
            .administrators-page .admin-list,
            .administrators-page .admin-row,
            .administrators-page .admin-row * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <p class="kicker">Master admin</p>
            <h1>Administrators</h1>
            <p class="lede">Remove platform administrator access. The person’s account, password, and workspace memberships stay. The last administrator cannot be removed, and you cannot remove your own access.</p>
        </div>
    </header>

    @if ($notice)
        <section class="notice" role="status" data-notice="removed">
            <p>{{ $notice }}</p>
        </section>
    @endif

    @if ($warning)
        <section class="notice" role="alert" data-warning="refused">
            <p>{{ $warning }}</p>
        </section>
    @endif

    @if (count($administrators) < 2)
        <section class="notice" data-last-admin="yes">
            <p>The last platform administrator stays.</p>
        </section>
    @endif

    <ul class="admin-list">
        @foreach ($administrators as $administrator)
            <li class="admin-row" data-admin="{{ $administrator['email'] }}" data-self="{{ $administrator['self'] ? 'yes' : 'no' }}">
                <div>
                    <strong>{{ $administrator['name'] }}</strong>
                    <span>{{ $administrator['email'] }}</span>
                </div>
                @if (! $administrator['self'] && count($administrators) > 1)
                    <button type="button" class="button danger small" wire:click="remove(@js($administrator['email']))" data-remove="{{ $administrator['email'] }}">Remove</button>
                @endif
            </li>
        @endforeach
    </ul>
</div>
