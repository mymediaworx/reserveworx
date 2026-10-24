<div class="page">
    <header class="page-head">
        <div>
            <h1>Branding</h1>
            <p class="lede">Your name, logo, and colors on your booking page and in this app.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    <div class="split">
        <form wire:submit="save" class="stack">
            <section class="panel">
                <h2>Business</h2>
                <div class="stack">
                    <div class="logo-row">
                        <x-avatar :name="$displayName" :logo="$previewLogo" size="lg" />
                        @if ($canUpdate)
                            <div class="stack" style="gap: .5rem">
                                <label class="button small">
                                    <x-icon name="upload" size="16" /> {{ $hasLogo ? 'Change logo' : 'Upload logo' }}
                                    <input type="file" wire:model="logo" accept="image/png,image/jpeg,image/webp,image/gif" class="sr-only">
                                </label>
                                @if ($hasLogo)
                                    <button type="button" class="link-button small" wire:click="removeLogo">Remove logo</button>
                                @endif
                                <span class="muted small" wire:loading wire:target="logo">Uploading…</span>
                            </div>
                        @endif
                    </div>
                    <p class="muted small">A square PNG or JPG, at least 200 × 200 pixels, up to 2 MB. Without a logo your initials show.</p>
                    @error('logo')<p class="error">{{ $message }}</p>@enderror
                    <label class="field">
                        <span>Business name customers see</span>
                        <input type="text" wire:model.live.debounce.400ms="displayName" maxlength="120" required @disabled(! $canUpdate)>
                    </label>
                    @error('displayName')<p class="error">{{ $message }}</p>@enderror
                </div>
            </section>

            <section class="panel">
                <h2>Colors</h2>
                <p class="muted small">Pick a starting point, then fine-tune. Text stays readable whatever you choose.</p>
                <div class="swatches" role="group" aria-label="Color presets">
                    @foreach ($presets as $name => [$p, $a])
                        <button type="button" class="swatch" wire:click="usePreset('{{ $name }}')" aria-pressed="{{ strtoupper($primary) === $p && strtoupper($accent) === $a ? 'true' : 'false' }}" @disabled(! $canUpdate)>
                            <span class="swatch-chip"><i style="background: {{ $p }}"></i><i style="background: {{ $a }}"></i></span>
                            {{ $name }}
                        </button>
                    @endforeach
                </div>

                <div class="form-row top-gap">
                    <div class="field">
                        <span>Main color</span>
                        <div class="color-field">
                            <input type="color" value="{{ $primaryValid ? strtolower($palette->primary) : '#0f5c4d' }}" wire:model.live.debounce.250ms="primary" aria-label="Main color picker" @disabled(! $canUpdate)>
                            <input type="text" wire:model.live.debounce.500ms="primary" maxlength="7" aria-label="Main color hex" @disabled(! $canUpdate)>
                        </div>
                        <small class="muted">Buttons, the selected tab, and links.</small>
                    </div>
                    <div class="field">
                        <span>Highlight color</span>
                        <div class="color-field">
                            <input type="color" value="{{ $accentValid ? strtolower($palette->accent) : '#f59e0b' }}" wire:model.live.debounce.250ms="accent" aria-label="Highlight color picker" @disabled(! $canUpdate)>
                            <input type="text" wire:model.live.debounce.500ms="accent" maxlength="7" aria-label="Highlight color hex" @disabled(! $canUpdate)>
                        </div>
                        <small class="muted">Badges and things that need attention.</small>
                    </div>
                </div>
                @error('primary')<p class="error">{{ $message }}</p>@enderror
                @error('accent')<p class="error">{{ $message }}</p>@enderror
                @if ($primaryValid && $palette->primaryIsLight())
                    <p class="notice attention top-gap small">This is a light color, so buttons use dark text and links use a darker shade to stay readable.</p>
                @elseif ($primaryValid && $palette->primaryNeedsDarkerText())
                    <p class="notice attention top-gap small">Links use a slightly darker shade of this color so they are easy to read.</p>
                @endif
            </section>

            <section class="panel">
                <h2>Customer support</h2>
                <p class="muted small">Shown to customers who need help with a booking.</p>
                <div class="form-row">
                    <label class="field grow"><span>Email</span><input type="email" wire:model="supportEmail" maxlength="255" @disabled(! $canUpdate)></label>
                    <label class="field grow"><span>Phone</span><input type="tel" wire:model="supportPhone" maxlength="40" @disabled(! $canUpdate)></label>
                </div>
                @error('supportEmail')<p class="error">{{ $message }}</p>@enderror
                @error('supportPhone')<p class="error">{{ $message }}</p>@enderror
            </section>

            @if ($canUpdate)
                <button type="submit" class="button primary" wire:loading.attr="disabled">Save branding</button>
            @else
                <p class="muted">Only owners and managers can change branding.</p>
            @endif
        </form>

        <aside class="stack">
            <section class="panel" aria-label="Preview">
                <h2>Preview</h2>
                <p class="muted small">Your booking page on a phone.</p>
                <div class="phone-preview" style="{{ $previewVariables }}">
                    <div class="pp-bar">
                        <x-avatar :name="$displayName" :logo="$previewLogo" size="sm" />
                        <span class="pp-name">{{ $displayName !== '' ? $displayName : 'Your business' }}</span>
                    </div>
                    <div class="pp-body">
                        <div class="pp-card">
                            <span>Kayak rental<small>$25.75, booking fee included</small></span>
                            <span class="pp-button" style="background: var(--brand); color: var(--brand-ink)">Book</span>
                        </div>
                        <div class="pp-card">
                            <span>Sunset tour<small>3 seats left</small></span>
                            <span class="pp-badge" style="background: var(--accent-soft); color: var(--accent-soft-ink)">Popular</span>
                        </div>
                        <a style="color: var(--brand-text); font-weight: 600; font-size: .9rem">Look up a booking</a>
                    </div>
                </div>
            </section>
        </aside>
    </div>
</div>
