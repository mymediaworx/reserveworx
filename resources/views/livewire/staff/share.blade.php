@php
    $formats = [
        'link' => ['Link', 'share'],
        'button' => ['Button', 'button'],
        'inline' => ['On your page', 'window'],
        'popup' => ['Pop-up', 'popup'],
    ];
@endphp
<div class="page narrow" x-data="{
    copiedKey: null,
    copy(text, key) {
        const done = () => { this.copiedKey = key; setTimeout(() => { if (this.copiedKey === key) this.copiedKey = null }, 2000) };
        const fallback = () => { const t = document.createElement('textarea'); t.value = text; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done() };
        try { navigator.clipboard ? navigator.clipboard.writeText(text).then(done, fallback) : fallback() } catch (e) { fallback() }
    }
}">
    <header class="page-head">
        <div>
            <h1>Share & embed</h1>
            <p class="lede">Put booking on your website, social profiles, and texts.</p>
        </div>
    </header>

    <section class="panel">
        <p class="step-title"><span class="step-num">1</span> What should customers book?</p>
        <label class="field">
            <span class="sr-only">Service</span>
            <select wire:model.live="target">
                <option value="all">Everything ({{ $services->count() }} {{ $services->count() === 1 ? 'service' : 'services' }})</option>
                @foreach ($services as $option)
                    <option value="{{ $option->id }}">Only {{ $option->name }}</option>
                @endforeach
            </select>
        </label>
    </section>

    <section class="panel top-gap">
        <p class="step-title"><span class="step-num">2</span> How do you want to share it?</p>
        <div class="segmented" role="group" aria-label="Share format">
            @foreach ($formats as $key => [$label, $icon])
                <button type="button" wire:click="choose('{{ $key }}')" aria-pressed="{{ $format === $key ? 'true' : 'false' }}">
                    <x-icon :name="$icon" size="18" /> {{ $label }}
                </button>
            @endforeach
        </div>

        <div class="stack top-gap">
            @if ($format === 'link')
                <p class="muted">Paste this link anywhere: your Google Business Profile, Instagram bio, Facebook page, or a text to a customer.</p>
                <div class="share-url">
                    <code class="link-text" aria-label="Booking link">{{ $url }}</code>
                    <button type="button" class="button primary" x-on:click="copy(@js($url), 'link')">
                        <x-icon name="copy" size="18" /><span x-text="copiedKey === 'link' ? 'Copied' : 'Copy link'">Copy link</span>
                    </button>
                </div>
                <div class="share-actions">
                    <a class="button" href="{{ $url }}" target="_blank" rel="noopener"><x-icon name="external" size="18" /> Open</a>
                    <a class="button" href="{{ $smsHref }}"><x-icon name="message" size="18" /> Text it</a>
                    <a class="button" href="{{ $mailHref }}"><x-icon name="mail" size="18" /> Email it</a>
                </div>
            @else
                @if ($format !== 'inline')
                    <label class="field">
                        <span>Button words</span>
                        <input type="text" wire:model.live.debounce.400ms="buttonText" maxlength="40">
                    </label>
                @endif

                <div class="preview-stage">
                    @if ($format === 'button')
                        {!! $buttonPreview !!}
                        <p class="preview-note">Opens your booking page in a new tab.</p>
                    @elseif ($format === 'popup')
                        {!! $popupPreview !!}
                        <p class="preview-note">Try it: your booking page opens in a window over the page, so customers stay on your site.</p>
                    @else
                        <iframe src="{{ $url }}" title="Preview of your booking page" data-reserveworx style="width:100%;height:420px;border:0;border-radius:10px;background:#fff" loading="lazy"></iframe>
                        <p class="preview-note">Your booking page, built into your web page. It grows to fit as customers move through booking.</p>
                    @endif
                </div>

                <div class="copy-box">
                    <label class="field">
                        <span>Code for your website</span>
                        <textarea class="code" readonly rows="5" x-on:focus="$event.target.select()">{{ $snippets[$format] }}</textarea>
                    </label>
                    <button type="button" class="button primary" x-on:click="copy(@js($snippets[$format]), '{{ $format }}')">
                        <x-icon name="copy" size="18" /><span x-text="copiedKey === @js($format) ? 'Copied' : 'Copy code'">Copy code</span>
                    </button>
                </div>

                <details>
                    <summary class="muted">Where do I paste this?</summary>
                    <ul class="how-to top-gap">
                        <li><strong>WordPress:</strong> add a <em>Custom HTML</em> block.</li>
                        <li><strong>Wix:</strong> Add, then <em>Embed code</em>, then <em>Embed HTML</em>.</li>
                        <li><strong>Squarespace:</strong> add a <em>Code</em> block.</li>
                        <li><strong>BrandWorx or GoHighLevel sites and funnels:</strong> add a <em>Custom HTML/Javascript</em> element.</li>
                        <li><strong>Anything else:</strong> look for “HTML”, “Code”, or “Embed”.</li>
                    </ul>
                </details>
            @endif
        </div>
    </section>

    <section class="panel top-gap">
        <details class="more" style="border: 0; margin: 0; padding: 0">
            <summary>More links</summary>
            <p class="muted small top-gap">Separate links you can turn off later, for example one for a partner or a printed flyer. A new link is shown once when you make it.</p>
            <div aria-live="polite">
                @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
            </div>
            @if ($newUrl)
                <div class="panel new-link">
                    <p class="small"><strong>Your new link.</strong> Copy it now; for security it is only shown this once.</p>
                    <div class="share-url">
                        <input type="text" readonly value="{{ $newUrl }}" aria-label="New link" x-on:focus="$event.target.select()">
                        <button type="button" class="button" x-on:click="copy(@js($newUrl), 'new')"><span x-text="copiedKey === 'new' ? 'Copied' : 'Copy'">Copy</span></button>
                    </div>
                </div>
            @endif
            @if ($tokens->isNotEmpty())
                <ul class="rows">
                    @foreach ($tokens as $token)
                        <li class="row">
                            <span class="grow"><strong>{{ $token->name }}</strong> <span class="muted small">Made {{ $token->created_at?->format('M j, Y') }}</span></span>
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
                <form wire:submit="createLink" class="form-row top-gap">
                    <label class="field compact grow"><span>Name this link</span><input type="text" wire:model="name" maxlength="80" required placeholder="Partner hotel"></label>
                    <button type="submit" class="button">Make a link</button>
                </form>
                @error('name')<p class="error">{{ $message }}</p>@enderror
            @endif
        </details>
    </section>
</div>
