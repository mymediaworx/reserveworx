<div class="page locations-page">
    <style>
        .locations-page .delete-location {
            flex: 1 0 100%;
            display: grid;
            gap: 0.6rem;
            min-width: 0;
            max-width: 100%;
            margin: 0.25rem 0 0;
            padding: 0.8rem 0.9rem;
            border: 1px solid var(--line);
            border-radius: var(--radius-lg);
            background: var(--page);
        }
        .locations-page .delete-location,
        .locations-page .delete-location * { overflow-wrap: anywhere; min-width: 0; max-width: 100%; }
        @media (max-width: 390px) {
            body.app:has(.fab):has(.locations-page) {
                height: auto;
                min-height: 100dvh;
                overflow: visible;
            }
            body.app:has(.fab):has(.locations-page) .app-shell,
            body.app:has(.fab):has(.locations-page) .app-main {
                height: auto;
                max-height: none;
                min-height: 0;
                overflow: visible;
            }
            body.app:has(.fab):has(.locations-page) .app-content {
                padding-bottom: calc(var(--tabbar) + var(--safe-bottom) + 5.5rem);
            }
            .locations-page,
            .locations-page .panel,
            .locations-page .split,
            .locations-page .stack,
            .locations-page .row,
            .locations-page .form-row,
            .locations-page .field,
            .locations-page .delete-location { min-width: 0; max-width: 100%; }
            .locations-page .form-row,
            .locations-page .delete-location .form-row { display: grid; grid-template-columns: minmax(0, 1fr); }
            .locations-page .row .button,
            .locations-page .form-row .button,
            .locations-page .delete-location .button,
            .locations-page .delete-location input,
            .locations-page .field input,
            .locations-page .field select { width: 100%; max-width: 100%; white-space: normal; }
            .locations-page h1,
            .locations-page .lede,
            .locations-page .delete-location { overflow-wrap: anywhere; }
        }
    </style>
    <header class="page-head">
        <div>
            <h1>Locations</h1>
            <p class="lede">Where customers pick up gear or meet a tour. Times are shown in each location’s own time zone. A new location starts in the business time zone.</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
    </div>

    <div class="split">
        <section class="panel">
            <h2>Your locations</h2>
            @if ($locations->isEmpty())
                <p class="empty">No locations yet. Add your first one to start setting up.</p>
            @else
                <ul class="rows">
                    @foreach ($locations as $location)
                        <li class="row" wire:key="location-{{ $location->id }}">
                            @if ($editingId === $location->id)
                                <form wire:submit="save" class="stack grow">
                                    <label class="field"><span>Name</span><input type="text" wire:model="editName" required maxlength="120"></label>
                                    <label class="field"><span>Time zone</span>
                                        <select wire:model="editTimezone">
                                            @foreach ($zones as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                                        </select>
                                    </label>
                                    <label class="field"><span>Status</span>
                                        <select wire:model="editStatus">
                                            <option value="active">Open</option>
                                            <option value="inactive">Closed</option>
                                        </select>
                                    </label>
                                    <label class="check"><input type="checkbox" wire:model.live="editDelivered"><span>Delivered from fleet. Gear for this location comes from the shared fleet, not from equipment kept here.</span></label>
                                    {{-- Always in the edit form. wire:show reveals it when Delivered from fleet is ticked, including before the next server render. --}}
                                    <div class="stack" wire:show="editDelivered" data-location-walk-ins @unless ($editDelivered) style="display: none" @endunless>
                                        <label class="check"><input type="checkbox" wire:model.live="editWalkIns"><span>Allow walk-ins (hold back units at this location for counter sales)</span></label>
                                        <div class="stack" wire:show="editWalkIns" @unless ($editWalkIns) style="display: none" @endunless>
                                            @forelse ($fleetItems as $item)
                                                <label class="field" wire:key="walk-in-hold-{{ $location->id }}-{{ $item->id }}"><span>Walk-in hold for {{ $item->name }}</span>
                                                    <input type="number" min="0" max="100000" wire:model="editReserves.{{ $item->id }}">
                                                </label>
                                                @error('editReserves.'.$item->id)<p class="error">{{ $message }}</p>@enderror
                                            @empty
                                                <p class="muted">Add a fleet item under Equipment before holding walk-in units.</p>
                                            @endforelse
                                        </div>
                                    </div>
                                    <div class="form-row">
                                        <button type="submit" class="button primary">Save</button>
                                        <button type="button" class="button" wire:click="$set('editingId', '')">Cancel</button>
                                    </div>
                                </form>
                            @else
                                <div class="grow">
                                    <strong>{{ $location->name }}</strong>
                                    <span class="muted">{{ $location->timezone }}{{ $location->status->value !== 'active' ? ', closed' : '' }}{{ $location->delivered_from_fleet ? ', delivered from fleet' : '' }}{{ $location->walk_ins_enabled ? ', walk-ins held' : '' }}</span>
                                </div>
                                @if ($canSetTax)
                                    <a class="button small" href="/staff/locations/{{ $location->id }}/tax" wire:navigate>Tax</a>
                                @endif
                                @if ($canManage)
                                    <button type="button" class="button small" wire:click="edit('{{ $location->id }}')">Edit</button>
                                @endif
                                @if ($canDelete && $deletingId !== $location->id)
                                    <button type="button" class="button small danger" wire:click="askDelete('{{ $location->id }}')">Delete</button>
                                @endif
                            @endif
                            @if ($canDelete && $deletePreview && $deletePreview['id'] === $location->id)
                                <section class="delete-location" aria-label="Delete {{ $deletePreview['name'] }}">
                                    <h3>Delete {{ $deletePreview['name'] }}?</h3>
                                    <p>{{ $deletePreview['summary'] }}</p>
                                    @foreach ($deletePreview['reasons'] as $reason)
                                        <p class="error">{{ $reason }}</p>
                                    @endforeach
                                    @if ($deletePreview['reasons'] === [])
                                        <form wire:submit="archive" class="stack">
                                            <p>Past bookings, payments, fees, and refunds stay on this location. Type {{ $deletePreview['name'] }} to delete it.</p>
                                            <label class="field"><span>Location name</span>
                                                <input type="text" wire:model="confirmName" autocomplete="off" spellcheck="false" required maxlength="120" aria-label="Location name">
                                            </label>
                                            @error('confirmName')<p class="error">{{ $message }}</p>@enderror
                                            <div class="form-row">
                                                <button type="submit" class="button danger">Delete location</button>
                                                <button type="button" class="button" wire:click="cancelDelete">Keep it</button>
                                            </div>
                                        </form>
                                    @else
                                        <button type="button" class="button" wire:click="cancelDelete">Keep it</button>
                                    @endif
                                </section>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($canManage)
                <form wire:submit="create" class="form-row top-gap">
                    <label class="field compact grow"><span>New location</span><input type="text" wire:model="name" placeholder="North dock" required maxlength="120"></label>
                    <label class="field compact"><span>Time zone</span>
                        <select wire:model="timezone">
                            @foreach ($zones as $z)<option value="{{ $z }}">{{ $z }}</option>@endforeach
                        </select>
                    </label>
                    <button type="submit" class="button primary">Add location</button>
                </form>
                @error('name')<p class="error">{{ $message }}</p>@enderror
            @endif
        </section>

        @if ($locations->isNotEmpty())
            <section class="panel">
                <h2>Hours and closures</h2>
                <label class="field compact">
                    <span>Location</span>
                    <select wire:model.live="hoursLocationId">
                        @foreach ($locations as $location)<option value="{{ $location->id }}">{{ $location->name }}</option>@endforeach
                    </select>
                </label>

                <h3>Opening hours</h3>
                @if ($hours->isEmpty())
                    <p class="muted">No hours set, so this location is open all day, every day.</p>
                @else
                    <ul class="rows compact">
                        @foreach ($hours as $row)
                            <li class="row">
                                <span class="grow">{{ $weekdays[$row->weekday] }} {{ substr($row->opens_local, 0, 5) }}–{{ substr($row->closes_local, 0, 5) }}</span>
                                @if ($canManage)<button type="button" class="button small" wire:click="removeHours('{{ $row->id }}')">Remove</button>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManage)
                    <form wire:submit="addHours" class="stack top-gap">
                        <fieldset class="days">
                            <legend>Days</legend>
                            @foreach ($weekdays as $num => $label)
                                <label class="check"><input type="checkbox" wire:model="hoursDays" value="{{ $num }}"><span>{{ $label }}</span></label>
                            @endforeach
                        </fieldset>
                        <div class="form-row">
                            <label class="field compact"><span>Opens</span><input type="time" wire:model="opens" required></label>
                            <label class="field compact"><span>Closes</span><input type="time" wire:model="closes" required></label>
                            <button type="submit" class="button">Add hours</button>
                        </div>
                        @error('closes')<p class="error">{{ $message }}</p>@enderror
                    </form>
                @endif

                <h3>Closed dates</h3>
                <p class="muted">{{ $industry->locationClosureNote() }} <a href="/staff/closures" wire:navigate>Closures</a>.</p>
                @if ($closures->isEmpty())
                    <p class="muted">No closed dates.</p>
                @else
                    <ul class="rows compact">
                        @foreach ($closures as $row)
                            <li class="row">
                                <span class="grow">{{ $row->starts_on->format('M j') }}{{ $row->ends_on->ne($row->starts_on) ? ' – '.$row->ends_on->format('M j') : '' }} <span class="muted">{{ $row->reason }}</span></span>
                                @if ($canManage)<button type="button" class="button small" wire:click="removeClosure('{{ $row->id }}')">Remove</button>@endif
                            </li>
                        @endforeach
                    </ul>
                @endif
                @if ($canManage)
                    <form wire:submit="addClosure" class="form-row top-gap">
                        <label class="field compact"><span>From</span><input type="date" wire:model="closedFrom" required></label>
                        <label class="field compact"><span>Until</span><input type="date" wire:model="closedUntil" required></label>
                        <label class="field compact grow"><span>Reason</span><input type="text" wire:model="closedReason" maxlength="200" placeholder="Holiday"></label>
                        <button type="submit" class="button">Add closure</button>
                    </form>
                    @error('closedUntil')<p class="error">{{ $message }}</p>@enderror
                @endif
            </section>
        @endif
    </div>
</div>
