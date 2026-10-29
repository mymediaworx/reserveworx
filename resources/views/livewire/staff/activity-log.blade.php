<div class="page audit-page">
    <style>
        @media (max-width: 390px) {
            .audit-page { min-width: 0; max-width: 100%; }
            .audit-page .page-head,
            .audit-page .filters { display: grid; min-width: 0; }
            .audit-page .filters .field,
            .audit-page .filters .button { width: 100%; min-width: 0; max-width: 100%; }
            .audit-page .audit-card,
            .audit-page .audit-card * { min-width: 0; overflow-wrap: anywhere; }
            .audit-page .audit-when { overflow-wrap: anywhere; max-width: 100%; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Audit log</h1>
            <p class="lede">Changes in this workspace, newest first. Times use the first location’s timezone with that abbreviation, or the business timezone when there is no location. On a fall-back morning both hours stay distinct. Filter by the stored action, such as booking.cancelled.</p>
        </div>
    </header>

    @error('action')
        <p class="flash bad" role="alert">{{ $message }}</p>
    @enderror

    <form class="filters" wire:submit="$refresh">
        <label class="field">
            <span>Action</span>
            <input type="search" name="action" wire:model="action" maxlength="255" value="{{ $action }}" placeholder="booking.cancelled" autocomplete="off">
        </label>
        <button type="submit" class="button">Filter</button>
    </form>

    <section class="panel top-gap">
        @if ($invalid)
            <p class="empty">Nothing is listed until the action is 255 characters or fewer.</p>
        @elseif ($rows === [])
            <p class="empty">{{ $filtering ? 'No activity matches that action.' : 'No activity yet.' }}</p>
        @else
            @if ($truncated)
                <p class="muted">Showing the latest {{ $limit }}.</p>
            @endif
            <ul class="rows">
                @foreach ($rows as $row)
                    <li class="row audit-card" wire:key="audit-{{ $row['id'] }}" data-audit="{{ $row['id'] }}">
                        <div class="grow">
                            <strong>{{ $row['label'] }}</strong>
                            <span class="muted audit-when">{{ $row['when'] }} · {{ $row['actor'] }}</span>
                            @if ($row['detail'] !== '')
                                <span class="muted">{{ $row['detail'] }}</span>
                            @endif
                            @if ($row['bookingUrl'])
                                <a href="{{ $row['bookingUrl'] }}" wire:navigate>Open booking</a>
                            @elseif ($row['subject'])
                                <span class="muted">Subject {{ $row['subject'] }}</span>
                            @endif
                            @if ($row['ip'])
                                <span class="muted">From {{ $row['ip'] }}</span>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
