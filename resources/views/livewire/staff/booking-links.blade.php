<div class="page narrow">
    <header class="page-head">
        <div>
            <h1>Booking links</h1>
            <p class="lede">Customers book through these links. Put one on your website, in your Google profile, or in a text message.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    @if ($newUrl)
        <section class="panel new-link" aria-labelledby="new-link-heading">
            <h2 id="new-link-heading">Your new booking link</h2>
            <p>Copy it now. For security it is only shown this once; you can always make another.</p>
            <label class="field">
                <span>Link</span>
                <input type="text" readonly value="{{ $newUrl }}" onclick="this.select()">
            </label>
            <p class="actions top-gap">
                <a class="button primary" href="{{ $newUrl }}" target="_blank" rel="noopener">Open booking page</a>
            </p>
            <label class="field top-gap">
                <span>Embed on your website</span>
                <textarea readonly rows="3" onclick="this.select()">&lt;iframe src="{{ $newUrl }}" title="Book online" style="width:100%;min-height:720px;border:0"&gt;&lt;/iframe&gt;</textarea>
            </label>
        </section>
    @endif

    <section class="panel">
        <h2>Links</h2>
        @if ($tokens->isEmpty())
            <p class="empty">No booking links yet. Make one to start taking online bookings.</p>
        @else
            <ul class="rows">
                @foreach ($tokens as $token)
                    <li class="row">
                        <span class="grow">
                            <strong>{{ $token->name }}</strong>
                            <span class="muted">Made {{ $token->created_at?->format('M j, Y') }}</span>
                        </span>
                        @if ($token->isActive())
                            <span class="badge badge-confirmed">On</span>
                            @if ($canManage)
                                <button type="button" class="button small danger" wire:click="revoke('{{ $token->id }}')" wire:confirm="Turn off this link? Anyone using it will no longer be able to book.">Turn off</button>
                            @endif
                        @else
                            <span class="badge badge-cancelled">Off</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canManage)
            <form wire:submit="create" class="form-row top-gap">
                <label class="field compact grow">
                    <span>Name this link</span>
                    <input type="text" wire:model="name" maxlength="80" required placeholder="Website">
                </label>
                <button type="submit" class="button primary">Make a link</button>
            </form>
            @error('name')<p class="error">{{ $message }}</p>@enderror
        @endif
    </section>
</div>
