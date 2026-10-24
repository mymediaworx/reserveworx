<div class="page closure-page">
    <header class="page-head">
        <div>
            <h1>Closures</h1>
            <p class="lede">Close a location, one category, or a single boat for a day or a few hours. A holiday can repeat every year. Confirmed bookings that overlap stay booked.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    @if ($locations->isEmpty())
        <section class="panel">
            <p class="empty">Add a location before you can close it.</p>
            <p><a class="button" href="/staff/setup/locations" wire:navigate>Add a location</a></p>
        </section>
    @else
        @if ($canManage)
            <section class="panel">
                <h2>{{ $editingId !== '' ? 'Edit closure' : 'Add a closure' }}</h2>
                <form wire:submit="save" class="stack">
                    <div class="form-row">
                        <label class="field grow">
                            <span>Location</span>
                            <select wire:model.live="locationId">
                                @foreach ($locations as $location)
                                    <option value="{{ $location->id }}">{{ $location->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="field">
                            <span>Applies to</span>
                            <select wire:model.live="scope">
                                <option value="location">The whole location</option>
                                <option value="category">One category</option>
                                <option value="resource">One boat or pool</option>
                            </select>
                        </label>
                    </div>
                    @error('location_id')<p class="error">{{ $message }}</p>@enderror

                    @if ($scope === 'category')
                        <label class="field">
                            <span>Category</span>
                            <select wire:model="resourceCategoryId">
                                <option value="">Choose a category</option>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        @error('resource_category_id')<p class="error">{{ $message }}</p>@enderror
                    @endif

                    @if ($scope === 'resource')
                        <label class="field">
                            <span>Boat or pool</span>
                            <select wire:model="resourceKey">
                                <option value="">Choose one</option>
                                @foreach ($assets as $asset)
                                    <option value="asset:{{ $asset->id }}">{{ $asset->name }}</option>
                                @endforeach
                                @foreach ($pools as $pool)
                                    <option value="pool:{{ $pool->id }}">{{ $pool->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        @error('resource_key')<p class="error">{{ $message }}</p>@enderror
                    @endif

                    <label class="field">
                        <span>Reason</span>
                        <input type="text" wire:model="reason" required maxlength="200" placeholder="Christmas">
                    </label>
                    @error('reason')<p class="error">{{ $message }}</p>@enderror

                    <fieldset class="choice">
                        <legend>When</legend>
                        <label class="check"><input type="radio" wire:model.live="span" value="day"><span>Full day</span></label>
                        <label class="check"><input type="radio" wire:model.live="span" value="hours"><span>A time range</span></label>
                        <label class="check"><input type="checkbox" wire:model="yearly"><span>Repeats every year</span></label>
                    </fieldset>

                    <div class="form-row">
                        <label class="field">
                            <span>{{ $yearly ? 'From month and day' : 'From' }}</span>
                            <input type="date" wire:model="startsOn" required>
                        </label>
                        <label class="field">
                            <span>{{ $yearly ? 'Until month and day' : 'Until' }}</span>
                            <input type="date" wire:model="endsOn" required>
                        </label>
                        @if ($span === 'hours')
                            <label class="field">
                                <span>Starts</span>
                                <input type="time" wire:model="startsLocal" required>
                            </label>
                            <label class="field">
                                <span>Ends</span>
                                <input type="time" wire:model="endsLocal" required>
                            </label>
                        @endif
                    </div>
                    @error('starts_on')<p class="error">{{ $message }}</p>@enderror
                    @error('ends_on')<p class="error">{{ $message }}</p>@enderror
                    @error('ends_local')<p class="error">{{ $message }}</p>@enderror
                    <p class="muted small">Times use that location’s time zone. The end is not included. A booking that only touches the end can still start.</p>

                    <div class="actions">
                        <button type="submit" class="button primary">{{ $editingId !== '' ? 'Save closure' : 'Add closure' }}</button>
                        @if ($editingId !== '')
                            <button type="button" class="button" wire:click="cancelEdit">Cancel</button>
                        @endif
                    </div>
                </form>
            </section>
        @endif

        <section class="panel">
            <h2>Scheduled closures</h2>
            @if ($closures->isEmpty())
                <p class="empty">No closures yet.</p>
            @else
                <ul class="rows">
                    @foreach ($closures as $closure)
                        <li class="row" wire:key="closure-{{ $closure->id }}">
                            <div class="grow">
                                <strong>{{ $closure->reason }}</strong>
                                <span class="muted">
                                    {{ $closure->location?->name }}
                                    ·
                                    @if ($closure->resource_id)
                                        {{ $closure->resource_type === 'pool' ? ($poolNames[$closure->resource_id] ?? 'Pool') : ($assetNames[$closure->resource_id] ?? 'Boat') }}
                                    @elseif ($closure->resource_category_id)
                                        {{ $closure->category?->name ?? 'Category' }}
                                    @else
                                        Whole location
                                    @endif
                                    ·
                                    @if ($closure->recurs_yearly)
                                        Every year
                                    @else
                                        Once
                                    @endif
                                    ·
                                    {{ $closure->starts_on->format('M j, Y') }}@if ($closure->ends_on->toDateString() !== $closure->starts_on->toDateString()) – {{ $closure->ends_on->format('M j, Y') }}@endif
                                    @unless ($closure->all_day)
                                        · {{ substr((string) $closure->starts_local, 0, 5) }}–{{ substr((string) $closure->ends_local, 0, 5) }}
                                    @else
                                        · All day
                                    @endunless
                                </span>
                                @php $hits = $overlaps[$closure->id] ?? collect(); @endphp
                                @if ($hits->isNotEmpty())
                                    <p class="muted small">Confirmed bookings that overlap. They stay booked.</p>
                                    <ul class="overlap-list">
                                        @foreach ($hits as $booking)
                                            <li>
                                                <a href="/staff/bookings/{{ $booking->id }}" wire:navigate>
                                                    {{ $booking->customer?->name ?: 'Booking' }}
                                                    · {{ \App\Support\Display::dateTime($booking->starts_at, $closure->location?->timezone ?? 'UTC') }}
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                            @if ($canManage)
                                <button type="button" class="button small" wire:click="edit('{{ $closure->id }}')">Edit</button>
                                <button type="button" class="button small" wire:click="remove('{{ $closure->id }}')">Remove</button>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif
</div>
