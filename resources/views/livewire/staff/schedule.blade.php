<div
    class="page schedule-page"
    @if ($selected === '' && $bookResource === '')
        wire:poll.60s="refreshCalendar"
    @endif
>
    <header class="page-head">
        <div>
            <h1>Schedule</h1>
            <p class="lede">
                @if ($view === 'day')
                    {{ $first->format('l, F j') }}
                @else
                    {{ $first->format('M j') }} – {{ $last->format('M j, Y') }}
                @endif
                <span class="muted">Each location uses its own timezone. Shaded edges are buffers.</span>
                @if ($canBook)
                    <span class="muted">{{ $industry->scheduleHint($view) }}</span>
                @endif
            </p>
        </div>
        <div class="toolbar">
            <button type="button" class="button" wire:click="shift(-1)" aria-label="Previous {{ $view }}">Previous</button>
            <button type="button" class="button" wire:click="today">Today</button>
            <button type="button" class="button" wire:click="shift(1)" aria-label="Next {{ $view }}">Next</button>
        </div>
    </header>

    <form class="filters" wire:submit.prevent>
        <label class="field compact">
            <span>View</span>
            <select wire:model.live="view">
                <option value="day">Day</option>
                <option value="week">Week</option>
            </select>
        </label>
        <label class="field compact">
            <span>Date</span>
            <input type="date" wire:model.live="date">
        </label>
        <label class="field compact">
            <span>Location</span>
            <select wire:model.live="location">
                <option value="">All locations</option>
                @foreach ($locations as $loc)
                    <option value="{{ $loc->id }}">{{ $loc->name }}</option>
                @endforeach
            </select>
        </label>
        <label class="field compact">
            <span>Category</span>
            <select wire:model.live="category">
                <option value="">All categories</option>
                @foreach ($categories as $cat)
                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                @endforeach
            </select>
        </label>
    </form>

    @if ($move)
        <section id="move-panel" class="panel move-panel" aria-labelledby="move-heading" tabindex="-1" wire:key="move-{{ $move['id'] }}" x-data x-init="$nextTick(() => { $el.focus({ preventScroll: true }); $el.scrollIntoView({ block: 'nearest' }); })">
            <div class="panel-head">
                <h2 id="move-heading">{{ $move['canMove'] ? 'Move' : $move['who'] }}</h2>
                <button type="button" class="button small" wire:click="closeMove">Close</button>
            </div>
            @if ($flash)
                <p class="flash ok">{{ $flash }}</p>
            @endif
            @if ($problem)
                <p class="flash bad" role="alert">{{ $problem }}</p>
            @endif
            <p class="lede">{{ $move['who'] }} · {{ $move['service'] }}</p>
            <p data-when>{{ $move['when'] }} <span class="muted small">{{ $move['zone'] }}</span></p>
            @if ($move['resource'] !== '')
                <p>On {{ $move['resource'] }}.</p>
            @endif
            <p><a class="button" href="{{ $move['href'] }}">Open booking</a></p>

            @if ($move['canMove'] && $move['tour'])
                <form wire:submit="moveBooking" class="stack top-gap">
                    @if ($move['departures'] === [])
                        <p class="muted">No other departures are open.</p>
                    @else
                        <label class="field">
                            <span>Departure</span>
                            <select wire:model="moveDeparture" required>
                                <option value="">Choose a departure</option>
                                @foreach ($move['departures'] as $departure)
                                    <option value="{{ $departure['id'] }}" @disabled(! $departure['available'])>{{ $departure['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        @error('moveDeparture')<p class="error">{{ $message }}</p>@enderror
                        <p class="muted small">The party keeps its price. A full or private departure stays closed.</p>
                        <button type="submit" class="button primary" wire:loading.attr="disabled" wire:target="moveBooking">
                            <span wire:loading.remove wire:target="moveBooking">Move booking</span>
                            <span wire:loading wire:target="moveBooking">Moving…</span>
                        </button>
                    @endif
                </form>
            @elseif ($move['canMove'])
                @php
                    $repeatedMove = is_string($moveStart) && preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $moveStart) === 1;
                @endphp
                <form wire:submit="moveBooking" class="stack top-gap">
                    <label class="field">
                        <span>New start</span>
                        <input type="{{ $repeatedMove ? 'text' : 'datetime-local' }}" wire:model="moveStart" required @if ($repeatedMove) inputmode="text" autocomplete="off" spellcheck="false" data-repeated-start="{{ $moveStart }}" @endif>
                    </label>
                    @if ($repeatedMove)
                        <p class="muted small" data-repeated-note>This start happens twice. The offset keeps this hour. A plain time is the earlier one.</p>
                    @endif
                    @error('moveStart')<p class="error">{{ $message }}</p>@enderror
                    <p class="muted small">Length stays {{ $move['length'] }}. The end moves with the start. Buffers stay on the resource.</p>
                    @if ($move['assets']->isNotEmpty())
                        <label class="field">
                            <span>Resource</span>
                            <select wire:model="moveAsset">
                                @foreach ($move['assets'] as $asset)
                                    <option value="{{ $asset->id }}">{{ $asset->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <p class="muted small">Another resource has to be active and in the same category at this location.</p>
                    @endif
                    @if ($suggestedTimes !== [] || $suggestedAssets !== [])
                        <div class="move-suggestions">
                            <p class="muted small">Suggestions from the same nearby-time search. Choosing one fills this form. {{ $industry->moveCheck() }}</p>
                            @foreach ($suggestedTimes as $time)
                                <button type="button" class="button small suggestion-time" wire:click="useTime('{{ $time['starts_at'] }}')" data-start="{{ $time['starts_at'] }}">{{ $time['label'] }}</button>
                            @endforeach
                            @foreach ($suggestedAssets as $asset)
                                <button type="button" class="button small suggestion-asset" wire:click="useAsset('{{ $asset['id'] }}')" data-asset="{{ $asset['id'] }}">{{ $asset['name'] }}</button>
                            @endforeach
                        </div>
                    @endif
                    <button type="submit" class="button primary" wire:loading.attr="disabled" wire:target="moveBooking">
                        <span wire:loading.remove wire:target="moveBooking">Move booking</span>
                        <span wire:loading wire:target="moveBooking">Moving…</span>
                    </button>
                </form>
            @elseif (! $move['confirmed'])
                <p>Only a confirmed booking can be moved.</p>
            @endif
        </section>
    @endif

    @include('livewire.staff.partials.schedule-booking')

    @if ($board['locations'] === [])
        <p class="empty">{{ $location !== '' ? 'That location is not in this workspace.' : 'Add a location to build the schedule.' }}</p>
    @else
        @foreach ($board['locations'] as $place)
            <section class="schedule-scroll" aria-labelledby="schedule-{{ $place['id'] }}">
                <header class="schedule-location">
                    <h2 id="schedule-{{ $place['id'] }}">{{ $place['name'] }}</h2>
                    <span class="muted small">{{ $place['abbreviation'] }} · {{ $place['timezone'] }}</span>
                </header>
                @if ($place['groups'] === [] && ! $place['summary']['has_blocks'])
                    <p class="empty small">{{ $category !== '' ? 'Nothing in this category.' : $industry->emptyLocation() }}</p>
                @endif
                <div @class(['schedule-grid', 'is-week' => $view === 'week']) style="--days: {{ count($place['days']) }}">
                    <div class="schedule-corner"></div>
                    <div class="schedule-head">
                        @foreach ($place['days'] as $day)
                            <div @class(['schedule-dayhead', 'is-today' => $day['today']]) data-date="{{ $day['date'] }}" data-minutes="{{ $day['minutes'] }}">
                                @if ($view === 'day')
                                    <span class="schedule-daytitle">{{ $day['heading'] }}</span>
                                    <div class="schedule-hours" aria-hidden="true">
                                        @foreach ($day['hours'] as $hour)
                                            @if ($loop->index % 2 === 0 || ($hour['repeated'] ?? false))
                                            <span class="schedule-hour" style="left: {{ $hour['left'] }}%">{{ $hour['label'] }}</span>
                                        @endif
                                        @endforeach
                                    </div>
                                @else
                                    <span class="weekday">{{ $day['weekday'] }}</span>
                                    <span class="daynum">{{ $day['number'] }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    @include('livewire.staff.partials.schedule-row', ['row' => $place['summary'], 'days' => $place['days'], 'selected' => $selected])

                    @foreach ($place['groups'] as $group)
                        <h3 class="schedule-category">{{ $group['name'] }}</h3>
                        @foreach ($group['rows'] as $row)
                            @include('livewire.staff.partials.schedule-row', ['row' => $row, 'days' => $place['days'], 'selected' => $selected])
                        @endforeach
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>
