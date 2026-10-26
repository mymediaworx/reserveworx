<div class="page">
    <header class="page-head">
        <div>
            <h1>Locations</h1>
            <p class="lede">Where customers pick up gear or meet a tour. Times are shown in each location’s own time zone.</p>
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
                        <li class="row">
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
                                    @if ($editDelivered)
                                        <label class="check"><input type="checkbox" wire:model.live="editWalkIns"><span>Hold units for walk-ins. Off closes booking once the day is fully committed. On keeps units here for the counter to sell.</span></label>
                                        @if ($editWalkIns)
                                            @forelse ($fleetItems as $item)
                                                <label class="field"><span>Walk-in hold for {{ $item->name }}</span>
                                                    <input type="number" min="0" max="100000" wire:model="editReserves.{{ $item->id }}">
                                                </label>
                                                @error('editReserves.'.$item->id)<p class="error">{{ $message }}</p>@enderror
                                            @empty
                                                <p class="muted">Add a fleet item under Equipment before holding walk-in units.</p>
                                            @endforelse
                                        @endif
                                    @endif
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
