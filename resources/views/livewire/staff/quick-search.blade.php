<div>
    <style>
        .staff-search-meta { min-width: 0; overflow-wrap: anywhere; }
        @media (max-width: 390px) {
            .staff-search-meta { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }
        }
    </style>
    @if ($open)
        <div class="staff-search-layer" data-staff-overlay="search">
            <button type="button" class="staff-search-backdrop" wire:click="close" aria-label="Close search"></button>
            <div class="staff-search" role="dialog" aria-modal="true" aria-labelledby="staff-search-label">
                <div class="staff-search-bar">
                    <x-icon name="search" size="20" />
                    <label id="staff-search-label" class="sr-only" for="staff-search-input">Search bookings, customers, and inventory</label>
                    <input
                        id="staff-search-input"
                        type="search"
                        wire:model.live.debounce.250ms="query"
                        wire:keydown.enter.prevent="openSelected"
                        wire:keydown.arrow-down.prevent="highlight(1)"
                        wire:keydown.arrow-up.prevent="highlight(-1)"
                        wire:keydown.escape="close"
                        placeholder="Name, email, phone, or reference"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        aria-autocomplete="list"
                        aria-controls="staff-search-results"
                        @if ($hitCount > 0) aria-activedescendant="staff-search-hit-{{ $selected }}" @endif
                        x-init="$el.focus()"
                    >
                    <button type="button" class="icon-button" wire:click="close" aria-label="Close search">
                        <x-icon name="x" size="22" />
                    </button>
                </div>
                <div id="staff-search-results" class="staff-search-results" role="listbox" aria-label="Search results">
                    @unless ($searching)
                        <p class="staff-search-hint">Type at least 2 letters or numbers. Results stay in this workspace and the locations you can use.</p>
                    @else
                        @php $index = 0; @endphp
                        @forelse ($groups as $group)
                            <section>
                                <h2 class="staff-search-group">{{ $group->label }}</h2>
                                @if ($group->hits === [])
                                    <p class="staff-search-hint">Nothing matches.</p>
                                @else
                                    <ul>
                                        @foreach ($group->hits as $hit)
                                            <li>
                                                <a
                                                    id="staff-search-hit-{{ $index }}"
                                                    href="{{ $hit->href }}"
                                                    wire:navigate
                                                    wire:key="hit-{{ $hit->type }}-{{ $hit->id }}"
                                                    role="option"
                                                    @class(['staff-search-hit', 'is-active' => $index === $selected])
                                                    aria-selected="{{ $index === $selected ? 'true' : 'false' }}"
                                                >
                                                    <span class="staff-search-title">{{ $hit->title }}</span>
                                                    <span class="staff-search-meta" @if ($hit->type === 'booking') data-when @endif>{{ $hit->meta }}</span>
                                                </a>
                                            </li>
                                            @php $index++; @endphp
                                        @endforeach
                                    </ul>
                                    @if ($group->lastPage > 1)
                                        <div class="staff-search-pages">
                                            <button type="button" class="button" wire:click="pageFor('{{ $group->type }}', {{ $group->page - 1 }})" @disabled($group->page <= 1)>Previous</button>
                                            <span>{{ $group->page }} of {{ $group->lastPage }}</span>
                                            <button type="button" class="button" wire:click="pageFor('{{ $group->type }}', {{ $group->page + 1 }})" @disabled($group->page >= $group->lastPage)>Next</button>
                                        </div>
                                    @endif
                                @endif
                            </section>
                        @empty
                            <p class="staff-search-hint">Nothing matches.</p>
                        @endforelse
                    @endunless
                </div>
            </div>
        </div>
    @endif

    @if ($help)
        <div class="staff-search-layer" data-staff-overlay="help">
            <button type="button" class="staff-search-backdrop" wire:click="close" aria-label="Close keyboard shortcuts"></button>
            <div class="staff-search staff-help" role="dialog" aria-modal="true" aria-labelledby="staff-help-label" wire:keydown.escape.window="close">
                <div class="staff-search-bar">
                    <h2 id="staff-help-label">Keyboard shortcuts</h2>
                    <button type="button" class="icon-button" wire:click="close" aria-label="Close keyboard shortcuts">
                        <x-icon name="x" size="22" />
                    </button>
                </div>
                <ul class="staff-help-list">
                    <li><kbd>Ctrl</kbd> <kbd>K</kbd> or <kbd>⌘</kbd> <kbd>K</kbd> <span>Search</span></li>
                    <li><kbd>G</kbd> then <kbd>B</kbd> <span>Bookings</span></li>
                    <li><kbd>G</kbd> then <kbd>D</kbd> <span>Dashboard</span></li>
                    @if ($canCreate)
                        <li><kbd>N</kbd> <span>New booking</span></li>
                    @endif
                    <li><kbd>?</kbd> <span>This list</span></li>
                </ul>
                <p class="staff-search-hint">Shortcuts pause while you are typing in a field.</p>
            </div>
        </div>
    @endif
</div>
