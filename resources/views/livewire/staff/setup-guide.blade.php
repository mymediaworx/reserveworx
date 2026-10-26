<div class="page narrow setup-guide">
    <style>
        .setup-guide { min-width: 0; }
        .setup-guide .guide-steps { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
        .setup-guide .guide-step { display: grid; grid-template-columns: 44px minmax(0, 1fr); gap: 12px; align-items: start; padding: 14px; border: 1px solid rgba(0,0,0,.1); border-radius: 12px; background: #fff; min-width: 0; }
        .setup-guide .guide-icon { width: 44px; height: 44px; border-radius: 50%; display: grid; place-items: center; background: rgba(0,0,0,.05); }
        .setup-guide .guide-step.is-done .guide-icon { background: #dcfce7; color: #166534; }
        .setup-guide .guide-step h2 { font-size: 1rem; margin: 0 0 4px; overflow-wrap: anywhere; }
        .setup-guide .guide-step p { margin: 0 0 8px; overflow-wrap: anywhere; }
        .setup-guide .guide-actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .setup-guide .guide-progress { width: 100%; height: 10px; }
        .setup-guide .button, .setup-guide .link-button { min-height: 44px; }
        .setup-guide :focus-visible { outline: 3px solid #2563eb; outline-offset: 2px; }
    </style>
    <header class="page-head">
        <div>
            <h1>Setup guide</h1>
            <p class="lede">Work through these steps to start taking bookings. Each button opens the real settings page.</p>
        </div>
    </header>

    <section class="panel" aria-labelledby="guide-progress-label">
        <p id="guide-progress-label"><strong>{{ $complete }} of {{ $total }}</strong> steps done</p>
        <progress class="guide-progress" value="{{ $complete }}" max="{{ $total }}" aria-labelledby="guide-progress-label">{{ $complete }} of {{ $total }}</progress>
        <div class="guide-actions top-gap">
            @if ($hidden)
                <button type="button" class="button" wire:click="showGuide" aria-pressed="false">Show setup guide on home</button>
            @else
                <button type="button" class="button" wire:click="hideGuide" aria-pressed="true">Hide setup guide</button>
            @endif
        </div>
        @if ($notice)
            <p role="status" class="top-gap">{{ $notice }}</p>
        @endif
    </section>

    <ol class="guide-steps top-gap" aria-label="Setup steps">
        @foreach ($steps as $index => $step)
            <li @class(['guide-step', 'is-done' => $step['state'] !== 'todo']) wire:key="step-{{ $step['key'] }}" data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}">
                <span class="guide-icon" aria-hidden="true"><x-icon :name="$step['state'] === 'done' ? 'check' : $step['icon']" size="22" /></span>
                <div>
                    <h2>{{ $index + 1 }}. {{ $step['title'] }}
                        <span @class(['badge', 'badge-confirmed' => $step['state'] === 'done', 'badge-held' => $step['state'] === 'todo'])>
                            {{ match ($step['state']) { 'done' => 'Done', 'skipped' => 'Skipped', default => 'To do' } }}
                        </span>
                    </h2>
                    <p class="muted">{{ $step['summary'] }}@if ($step['detected']) <span>We found this in your settings.</span>@endif</p>
                    <div class="guide-actions">
                        @if ($step['href'])
                            <a class="button primary" href="{{ $step['href'] }}" wire:navigate>{{ $step['action'] }}</a>
                        @else
                            <span class="badge">Ask support</span>
                        @endif
                        @if (! $step['detected'])
                            @if ($step['state'] === 'todo')
                                <button type="button" class="link-button" wire:click="markDone('{{ $step['key'] }}')">Mark done<span class="sr-only">: {{ $step['title'] }}</span></button>
                                <button type="button" class="link-button" wire:click="skip('{{ $step['key'] }}')">Skip<span class="sr-only">: {{ $step['title'] }}</span></button>
                            @else
                                <button type="button" class="link-button" wire:click="undo('{{ $step['key'] }}')">Undo<span class="sr-only">: {{ $step['title'] }}</span></button>
                            @endif
                        @endif
                    </div>
                </div>
            </li>
        @endforeach
    </ol>
</div>
