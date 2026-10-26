<div class="page customers-page">
    <style>
        @media (max-width: 390px) {
            .customers-page { min-width: 0; max-width: 100%; }
            .customers-page .page-head,
            .customers-page .filters,
            .customers-page .customer-actions { display: grid; min-width: 0; }
            .customers-page .filters .field,
            .customers-page .filters .button,
            .customers-page .customer-actions .button,
            .customers-page .customer-actions a { width: 100%; min-width: 0; max-width: 100%; }
            .customers-page .customer-card,
            .customers-page .customer-card * { min-width: 0; overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Customer records</h1>
            <p class="lede">Download one customer’s record, then remove their name, email, and phone. Bookings, payments, and signed waivers stay. How long a signed waiver is kept is still undecided, so this page does not delete those rows. A download link lasts 30 minutes.</p>
        </div>
    </header>

    @if ($flash)
        <p class="flash {{ $flashTone === 'bad' ? 'bad' : 'ok' }}" role="{{ $flashTone === 'bad' ? 'alert' : 'status' }}">{{ $flash }}</p>
    @endif

    <form class="filters" wire:submit="$refresh">
        <label class="field">
            <span>Name, email, or phone</span>
            <input type="search" name="q" wire:model="q" maxlength="255" value="{{ $q }}">
        </label>
        <button type="submit" class="button">Search</button>
    </form>

    <label class="field top-gap">
        <span>
            <input type="checkbox" wire:model="confirmWithoutFile">
            Remove personal details even when a download file is not ready.
        </span>
    </label>

    <section class="panel top-gap">
        @if ($rows === [])
            <p class="empty">{{ $searching ? 'No customers match that search.' : 'No customers yet.' }}</p>
        @else
            <ul class="rows">
                @foreach ($rows as $row)
                    <li class="row customer-card" wire:key="customer-{{ $row['id'] }}">
                        <div class="grow">
                            <strong>{{ $row['name'] }}</strong>
                            <span class="muted">
                                {{ $row['email'] ?: 'No email' }}
                                @if ($row['phone'])
                                    · {{ $row['phone'] }}
                                @endif
                            </span>
                            @if ($row['anonymized'])
                                <span class="muted">Personal details removed</span>
                            @elseif ($row['downloadUrl'])
                                <a href="{{ $row['downloadUrl'] }}">Download</a>
                            @elseif ($this->statusIsQueued($row['status'], $row['downloadUrl']))
                                <span class="muted">Preparing the download</span>
                            @elseif ($this->statusIsFailed($row['status']))
                                <span class="muted">Could not prepare the download.</span>
                            @endif
                        </div>
                        <div class="customer-actions">
                            <button type="button" class="button small" wire:click="exportCustomer('{{ $row['id'] }}')">Download their record</button>
                            @unless ($row['anonymized'])
                                <button type="button" class="button small" wire:click="eraseCustomer('{{ $row['id'] }}')">Remove personal details</button>
                            @endunless
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
