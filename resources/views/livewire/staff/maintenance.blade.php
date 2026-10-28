@php use App\Support\Display; @endphp
<div class="page maintenance-page">
    <style>
        .maintenance-page { min-width: 0; }
        .maintenance-page .window-list { display: grid; gap: 0.6rem; margin: 0; padding: 0; list-style: none; }
        .maintenance-page .window-card { display: grid; gap: 0.3rem; min-width: 0; padding: 0.8rem 0.9rem; border: 1px solid var(--line); border-radius: var(--radius-lg); background: var(--surface); }
        .maintenance-page .window-card.is-live { border-color: #FCD34D; background: #FFFBEB; }
        .maintenance-page .window-card .meta { display: flex; flex-wrap: wrap; gap: 0.35rem 0.6rem; align-items: center; }
        .maintenance-page .affected { margin-top: 0.25rem; }
        .maintenance-page .inspection-row { display: grid; gap: 0.5rem; }
        .maintenance-page .inspection-row .done-form { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: end; }
        .maintenance-page .inspection-row .done-form .field { flex: 1 1 12rem; }
        @media (max-width: 390px) {
            .maintenance-page .when,
            .maintenance-page .when * { overflow-wrap: anywhere; min-width: 0; max-width: 100%; }
        }
        @media (max-width: 480px) {
            .maintenance-page .window-card,
            .maintenance-page .window-card *,
            .maintenance-page .inspection-row * { overflow-wrap: anywhere; min-width: 0; max-width: 100%; }
            .maintenance-page input:not([type=radio]):not([type=checkbox]),
            .maintenance-page select,
            .maintenance-page .form-row .button,
            .maintenance-page .done-form .button { width: 100%; max-width: 100%; }
        }
    </style>

    <header class="page-head">
        <div>
            <h1>Maintenance</h1>
            <p class="lede">Take a boat or pool out of service for a set time, and keep repeating inspections on schedule. Bookings that overlap are flagged for you to follow up. Nothing is cancelled or moved for you. @if ($zoneName)Times are in {{ $zoneName }}.@else Times use each location's time zone.@endif</p>
        </div>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
        @error('window')<p class="error">{{ $message }}</p>@enderror
    </div>

    @if ($resources->isEmpty() && $resource === '')
        <section class="panel">
            <p class="empty">No boats or pools here yet.</p>
            <p><a class="button" href="/staff/setup/resources" wire:navigate>Add inventory</a></p>
        </section>
    @else
        <form class="filters" wire:submit.prevent>
            @if ($locations->count() > 1)
                <label class="field compact">
                    <span>Location</span>
                    <select wire:model.live="location">
                        <option value="">All locations</option>
                        @foreach ($locations as $option)
                            <option value="{{ $option->id }}">{{ $option->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
            <label class="field compact grow">
                <span>Boat or pool</span>
                <select wire:model.live="resource">
                    <option value="">Everything</option>
                    @foreach ($resources as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </form>

        <div class="split top-gap">
            <div class="stack">
                <section class="panel" aria-labelledby="live-heading">
                    <h2 id="live-heading">In progress</h2>
                    @include('livewire.staff.partials.maintenance-windows', ['windows' => $inProgress, 'live' => true, 'canEnd' => $canManage, 'emptyText' => 'Nothing is out of service right now.'])
                </section>

                <section class="panel" aria-labelledby="upcoming-heading">
                    <h2 id="upcoming-heading">Upcoming</h2>
                    @include('livewire.staff.partials.maintenance-windows', ['windows' => $upcoming, 'live' => false, 'canEnd' => $canManage, 'emptyText' => 'No maintenance or blocks scheduled.'])
                </section>

                <section class="panel" aria-labelledby="inspections-heading">
                    <h2 id="inspections-heading">Inspections</h2>
                    @if ($inspections->isEmpty())
                        <p class="empty">No repeating inspections yet.</p>
                    @else
                        <ul class="rows">
                            @foreach ($inspections as $row)
                                @php
                                    $schedule = $row['schedule'];
                                    $resource = $schedule->asset ?? $schedule->pool;
                                    $zone = \App\Support\DisplayZone::forLocation($resource?->location);
                                @endphp
                                <li class="row inspection-row" wire:key="inspection-{{ $schedule->id }}">
                                    <div>
                                        <strong>{{ $schedule->name }}</strong>
                                        @if ($row['due'])
                                            <span class="badge badge-payment">Due</span>
                                        @endif
                                        <span class="muted">
                                            {{ $resource?->name }}{{ $schedule->pool_id !== null ? ' (pool)' : '' }}
                                            ·
                                            @if ($schedule->interval_days !== null)
                                                Every {{ $schedule->interval_days }} {{ $schedule->interval_days === 1 ? 'day' : 'days' }}
                                                @if ($schedule->next_due_at)
                                                    · next {{ Display::date($schedule->next_due_at, $zone) }}
                                                @endif
                                            @else
                                                Every {{ $schedule->interval_uses }} {{ $schedule->interval_uses === 1 ? 'use' : 'uses' }}
                                                · {{ $row['uses'] }} since last done
                                            @endif
                                            @if ($schedule->last_done_at)
                                                · last done {{ Display::date($schedule->last_done_at, $zone) }}
                                            @endif
                                        </span>
                                    </div>
                                    @if ($canManage)
                                        <form class="done-form" wire:submit="markDone('{{ $schedule->id }}')">
                                            <label class="field compact">
                                                <span>Note (optional)</span>
                                                <input type="text" wire:model="doneNote.{{ $schedule->id }}" maxlength="300" placeholder="All good">
                                            </label>
                                            <button type="submit" class="button">Mark done</button>
                                        </form>
                                        @error('doneNote.'.$schedule->id)<p class="error">{{ $message }}</p>@enderror
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="panel" aria-labelledby="past-heading">
                    <h2 id="past-heading">Past</h2>
                    @if ($past->isEmpty())
                        <p class="empty">No past maintenance.</p>
                    @else
                        <ul class="rows compact">
                            @foreach ($past as $window)
                                @php $zone = \App\Support\DisplayZone::forLocation(($window->asset ?? $window->pool)?->location); @endphp
                                <li class="row" wire:key="past-{{ $window->id }}">
                                    <span class="grow">
                                        <strong>{{ ($window->asset ?? $window->pool)?->name }}</strong>
                                        <span class="muted when" data-when>{{ $window->reason }} · {{ Display::rangeWithZone($window->starts_at, $window->ends_at, $zone) }}</span>
                                        @if ($window->ended_at !== null && $window->ended_at->lessThan($window->ends_at))
                                            <span class="muted when" data-when>Ended {{ Display::dateTimeWithZone($window->ended_at, $zone) }}</span>
                                        @endif
                                    </span>
                                    <span class="badge">{{ $window->kind->label() }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>

            @if ($canManage)
                <div class="stack">
                    <section class="panel" aria-labelledby="schedule-heading">
                        <h2 id="schedule-heading">Take out of service</h2>
                        <form wire:submit="scheduleWindow" class="stack">
                            <label class="field">
                                <span>Boat or pool</span>
                                <select wire:model="windowResource" required>
                                    <option value="">Choose one</option>
                                    @foreach ($resources as $key => $label)
                                        <option value="{{ $key }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            @error('windowResource')<p class="error">{{ $message }}</p>@enderror

                            <fieldset class="choice">
                                <legend>Type</legend>
                                @foreach ($kinds as $kind)
                                    <label class="check">
                                        <input type="radio" wire:model="windowKind" value="{{ $kind->value }}">
                                        <span>{{ $kind->label() }}{{ $kind->value === 'block' ? ': held back for another reason, like a private event' : ': service, repair, or cleaning' }}</span>
                                    </label>
                                @endforeach
                            </fieldset>

                            <div class="form-row">
                                <label class="field compact grow">
                                    <span>From</span>
                                    <input type="datetime-local" wire:model="windowStarts" required>
                                </label>
                                <label class="field compact grow">
                                    <span>Until</span>
                                    <input type="datetime-local" wire:model="windowEnds" required>
                                </label>
                            </div>
                            @error('windowStarts')<p class="error">{{ $message }}</p>@enderror
                            @error('windowEnds')<p class="error">{{ $message }}</p>@enderror

                            <label class="field">
                                <span>Reason</span>
                                <input type="text" wire:model="windowReason" maxlength="160" required placeholder="Engine service">
                            </label>
                            @error('windowReason')<p class="error">{{ $message }}</p>@enderror

                            <p class="muted small">Times use that boat’s location time zone. A repeated hour starts at the earlier time and ends at the later time. A time that does not exist is not saved. New bookings can’t use it during this time. Existing bookings stay booked and are flagged.</p>
                            <button type="submit" class="button primary" wire:loading.attr="disabled">Save</button>
                        </form>
                    </section>

                    <section class="panel" aria-labelledby="add-inspection-heading">
                        <h2 id="add-inspection-heading">Add an inspection</h2>
                        @if ($resources->isEmpty())
                            <p class="muted">Add a boat or pool under inventory first.</p>
                        @else
                            <form wire:submit="addInspection" class="stack">
                                <label class="field">
                                    <span>Boat or pool</span>
                                    <select wire:model="inspectionResource" required>
                                        <option value="">Choose one</option>
                                        @foreach ($resources as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                @error('inspectionResource')<p class="error">{{ $message }}</p>@enderror
                                <label class="field">
                                    <span>Inspection</span>
                                    <input type="text" wire:model="inspectionName" maxlength="120" required placeholder="Safety check">
                                </label>
                                @error('inspectionName')<p class="error">{{ $message }}</p>@enderror
                                <div class="form-row">
                                    <label class="field compact">
                                        <span>Every</span>
                                        <input type="number" min="1" wire:model="inspectionEvery" required inputmode="numeric">
                                    </label>
                                    <label class="field compact grow">
                                        <span>Unit</span>
                                        <select wire:model="inspectionUnit">
                                            <option value="days">Days</option>
                                            <option value="uses">Rentals (uses)</option>
                                        </select>
                                    </label>
                                </div>
                                @error('inspectionEvery')<p class="error">{{ $message }}</p>@enderror
                                <button type="submit" class="button">Add inspection</button>
                            </form>
                        @endif
                    </section>
                </div>
            @endif
        </div>
    @endif
</div>
