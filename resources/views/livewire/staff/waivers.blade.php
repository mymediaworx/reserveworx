@php use App\Support\Display; @endphp
<div class="page">
    <header class="page-head">
        <div>
            <h1>Waivers</h1>
            <p class="lede">Guests sign the newest version of each waiver from their confirmation page or email, before they arrive. Check-in shows who still needs to sign.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    <div class="split">
        <section class="panel">
            <h2>In use</h2>
            @if ($current->isEmpty())
                <p class="empty">No waivers yet. Guests are not asked to sign anything.</p>
            @else
                <ul class="rows">
                    @foreach ($current as $waiver)
                        <li class="row" wire:key="waiver-{{ $waiver->id }}">
                            <div class="grow">
                                <strong>{{ $waiver->name }}</strong>
                                <span class="muted">Version {{ $waiver->version }} · published {{ Display::date($waiver->created_at, 'UTC') }} · {{ (int) ($signatureCounts[$waiver->id] ?? 0) }} {{ (int) ($signatureCounts[$waiver->id] ?? 0) === 1 ? 'signature' : 'signatures' }}</span>
                            </div>
                            @if ($canManage)
                                <button type="button" class="button small" wire:click="edit('{{ $waiver->id }}')">New version</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($history->isNotEmpty())
                <details class="top-gap">
                    <summary>Earlier versions</summary>
                    <ul class="rows compact">
                        @foreach ($history as $old)
                            <li class="row"><span class="grow">{{ $old->name }} <span class="muted">version {{ $old->version }} · {{ Display::date($old->created_at, 'UTC') }}</span></span></li>
                        @endforeach
                    </ul>
                </details>
            @endif
        </section>

        @if ($canManage)
            <section class="panel">
                <h2>Publish a waiver</h2>
                <form wire:submit="publish" class="stack">
                    <label class="field">
                        <span>Name</span>
                        <input type="text" wire:model="name" maxlength="80" required placeholder="Liability waiver">
                    </label>
                    @error('name')<p class="error">{{ $message }}</p>@enderror
                    <label class="field">
                        <span>Waiver text</span>
                        <textarea wire:model="body" rows="12" maxlength="20000" required></textarea>
                    </label>
                    @error('body')<p class="error">{{ $message }}</p>@enderror
                    <p class="muted small">Using an existing name publishes a new version. Guests who signed an earlier version are asked to sign again. Have your own lawyer review the text; ReserveWorx does not.</p>
                    <button type="submit" class="button primary">Publish</button>
                </form>
            </section>
        @endif
    </div>
</div>
