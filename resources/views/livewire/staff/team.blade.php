@php use App\Support\Display; @endphp
<div class="page narrow">
    <header class="page-head">
        <h1>Team</h1>
        <p>Invite someone by email and give them a role. The link expires in 7 days. Locations stay on every site until you limit them.</p>
    </header>

    <div aria-live="polite">
        @if ($flash)<p class="flash ok">{{ $flash }}</p>@endif
        @if ($problem)<p class="flash bad" role="alert">{{ $problem }}</p>@endif
        @error('email')<p class="error" role="alert">{{ $message }}</p>@enderror
        @error('role')<p class="error" role="alert">{{ $message }}</p>@enderror
        @error('locations')<p class="error" role="alert">{{ $message }}</p>@enderror
        @error('team')<p class="error" role="alert">{{ $message }}</p>@enderror
    </div>

    <section class="panel">
        <h2>Invite by email</h2>
        <form wire:submit="invite" class="stack">
            <label class="field">
                <span>Email</span>
                <input type="email" wire:model="email" maxlength="255" autocomplete="off" required>
            </label>
            <label class="field">
                <span>Role</span>
                <select wire:model="role" required>
                    @foreach ($roleOptions as $option)
                        <option value="{{ $option->slug }}">{{ $option->name }}</option>
                    @endforeach
                </select>
            </label>
            @include('livewire.staff.partials.location-access', [
                'allModel' => 'allLocations',
                'idsModel' => 'locationIds',
                'all' => $allLocations,
                'selected' => $locationIds,
            ])
            <button type="submit" class="button primary">Send invitation</button>
        </form>
    </section>

    <section class="panel">
        <h2>People on the team</h2>
        <ul class="team-list">
            @forelse ($members as $member)
                <li class="team-card">
                    <div class="team-who">
                        <strong>{{ $member->user?->name }}</strong>
                        <span>{{ $member->user?->email }}</span>
                    </div>
                    <div class="toolbar">
                        <span class="badge">{{ $member->role?->name }}</span>
                        @if ($member->user_id === $actorId)
                            <span class="badge">You</span>
                        @endif
                    </div>
                    @include('livewire.staff.partials.location-access-form', ['person' => $member])
                    <form wire:submit="saveRole('{{ $member->id }}')" class="form-row">
                        <label class="field grow">
                            <span class="sr-only">Role for {{ $member->user?->name }}</span>
                            <select wire:model="drafts.{{ $member->id }}">
                                @foreach ($roleOptions as $option)
                                    <option value="{{ $option->slug }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="button">Update role</button>
                    </form>
                    <button type="button" class="button danger" wire:click="removeMember('{{ $member->id }}')" wire:confirm="Remove this person from the team?">Remove</button>
                </li>
            @empty
                <li class="team-card"><p>No one is on the team yet.</p></li>
            @endforelse
        </ul>
    </section>

    <section class="panel">
        <h2>Pending invitations</h2>
        <ul class="team-list">
            @forelse ($invites as $invite)
                <li class="team-card">
                    <div class="team-who">
                        <strong>{{ $invite->user?->email }}</strong>
                        <span>
                            {{ $invite->role?->name }}
                            @if ($invite->invite_expires_at)
                                · expires {{ Display::dateTime($invite->invite_expires_at, $zone) }}
                            @endif
                        </span>
                    </div>
                    @include('livewire.staff.partials.location-access-form', ['person' => $invite])
                    <form wire:submit="saveRole('{{ $invite->id }}')" class="form-row">
                        <label class="field grow">
                            <span class="sr-only">Role for {{ $invite->user?->email }}</span>
                            <select wire:model="drafts.{{ $invite->id }}">
                                @foreach ($roleOptions as $option)
                                    <option value="{{ $option->slug }}">{{ $option->name }}</option>
                                @endforeach
                            </select>
                        </label>
                        <button type="submit" class="button">Update role</button>
                    </form>
                    <div class="team-actions">
                        <button type="button" class="button" wire:click="resend('{{ $invite->id }}')">Resend</button>
                        <button type="button" class="button danger" wire:click="revokeInvite('{{ $invite->id }}')" wire:confirm="Revoke this invitation?">Revoke</button>
                    </div>
                </li>
            @empty
                <li class="team-card"><p>No invitations are waiting.</p></li>
            @endforelse
        </ul>
    </section>
</div>
