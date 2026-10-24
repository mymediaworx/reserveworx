<div class="page schedule-page">
    <header class="page-head">
        <div>
            <h1>Schedule</h1>
            <p class="lede">
                @if ($view === 'day')
                    {{ $first->format('l, F j') }}
                @else
                    {{ $first->format('M j') }} – {{ $last->format('M j, Y') }}
                @endif
                <span class="muted">Times are local to each location. Shaded edges are buffers.</span>
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
                    <p class="empty small">{{ $category !== '' ? 'Nothing in this category.' : 'No boats or gear at this location yet.' }}</p>
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
                                            @if ($loop->index % 2 === 0)
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

                    @include('livewire.staff.partials.schedule-row', ['row' => $place['summary'], 'days' => $place['days']])

                    @foreach ($place['groups'] as $group)
                        <h3 class="schedule-category">{{ $group['name'] }}</h3>
                        @foreach ($group['rows'] as $row)
                            @include('livewire.staff.partials.schedule-row', ['row' => $row, 'days' => $place['days']])
                        @endforeach
                    @endforeach
                </div>
            </section>
        @endforeach
    @endif
</div>
