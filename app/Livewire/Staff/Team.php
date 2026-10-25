<?php

namespace App\Livewire\Staff;

use App\Enums\MembershipStatus;
use App\Livewire\Concerns\WithinTenant;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Role;
use App\Services\Team\RoleGuard;
use App\Services\Team\WorkspaceTeam;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.staff')]
#[Title('Team')]
class Team extends Component
{
    use WithinTenant;

    public string $email = '';

    public string $role = 'staff';

    public bool $allLocations = true;

    /** @var list<string> */
    public array $locationIds = [];

    public ?string $flash = null;

    public ?string $problem = null;

    /** @var array<string, string> */
    public array $drafts = [];

    /** @var array<string, bool> */
    public array $memberAll = [];

    /** @var array<string, list<string>> */
    public array $memberLocations = [];

    public function mount(RoleGuard $guard): void
    {
        $this->authorize('create', Membership::class);
        $slugs = $guard->assignableSlugs($this->currentUser(), $this->tenant()->id);
        $this->role = in_array('staff', $slugs, true) ? 'staff' : ($slugs[0] ?? 'staff');
        $this->syncDrafts();
    }

    public function invite(WorkspaceTeam $team, RoleGuard $guard): void
    {
        $this->authorize('create', Membership::class);
        $this->clearMessages();
        $assignable = $guard->assignableSlugs($this->currentUser(), $this->tenant()->id);
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::in($assignable)],
        ]);

        if ($this->throttled()) {
            return;
        }

        $result = $team->invite(
            $this->currentUser(),
            $this->email,
            $this->role,
            request(),
            $this->allLocations,
            $this->locationIds,
        );
        $this->hitInviteLimit();
        $this->reset('email');
        $this->allLocations = true;
        $this->locationIds = [];
        $this->role = in_array('staff', $assignable, true) ? 'staff' : ($assignable[0] ?? 'staff');
        $this->flash = $result->mailed
            ? 'Invitation sent. The link expires in 7 days.'
            : 'Invitation saved, but the email could not be sent. Resend it.';
        $this->syncDrafts();
    }

    public function resend(string $membershipId, WorkspaceTeam $team): void
    {
        $this->clearMessages();
        $membership = $this->findMembership($membershipId);

        if ($this->throttled()) {
            return;
        }

        $result = $team->resend($this->currentUser(), $membership, request());
        $this->hitInviteLimit();
        $this->flash = $result->mailed
            ? 'Invitation sent again. The new link expires in 7 days.'
            : 'The invitation is still waiting, but the email could not be sent.';
        $this->syncDrafts();
    }

    public function revokeInvite(string $membershipId, WorkspaceTeam $team): void
    {
        $this->clearMessages();
        $membership = $this->findMembership($membershipId);
        $team->revokeInvite($this->currentUser(), $membership, request());
        $this->flash = 'Invitation revoked.';
        $this->syncDrafts();
    }

    public function saveRole(string $membershipId, WorkspaceTeam $team): void
    {
        $this->clearMessages();
        $membership = $this->findMembership($membershipId);
        $chosen = $this->drafts[$membershipId] ?? '';
        $before = $membership->role?->slug;
        $updated = $team->changeRole($this->currentUser(), $membership, $chosen, request());
        $this->flash = $updated->role?->slug === $before
            ? 'That is already their role.'
            : 'Role updated.';
        $this->syncDrafts();
    }

    public function removeMember(string $membershipId, WorkspaceTeam $team): void
    {
        $this->clearMessages();
        $membership = $this->findMembership($membershipId);
        $removingSelf = $membership->user_id === $this->currentUser()->id;
        $team->remove($this->currentUser(), $membership, request());

        if ($removingSelf) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
            $this->redirect('/staff/login');

            return;
        }

        $this->flash = 'Removed from the team.';
        $this->syncDrafts();
    }

    public function saveLocations(string $membershipId, WorkspaceTeam $team): void
    {
        $this->clearMessages();
        $membership = $this->findMembership($membershipId);
        $all = (bool) ($this->memberAll[$membershipId] ?? true);
        $ids = $this->memberLocations[$membershipId] ?? [];
        $team->assignLocations($this->currentUser(), $membership, $all, $ids, request());
        $this->flash = $all ? 'They can work at every location.' : 'Location access updated.';
        $this->syncDrafts();
    }

    public function render(RoleGuard $guard): View
    {
        $people = Membership::query()
            ->with(['user', 'role'])
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])
            ->orderBy('created_at')
            ->get();
        $slugs = $guard->assignableSlugs($this->currentUser(), $this->tenant()->id);

        return view('livewire.staff.team', [
            'members' => $people->where('status', MembershipStatus::Active)->values(),
            'invites' => $people->where('status', MembershipStatus::Invited)->values(),
            'roleOptions' => $this->roleOptions($slugs),
            'locations' => Location::query()->orderBy('name')->get(['id', 'name']),
            'zone' => $this->defaultTimezone(),
            'actorId' => $this->currentUser()->id,
        ]);
    }

    private function findMembership(string $membershipId): Membership
    {
        $membership = Membership::query()->whereKey($membershipId)->first();
        abort_unless($membership instanceof Membership, 404);
        $this->authorize('update', $membership);
        $membership->loadMissing('role');

        return $membership;
    }

    private function syncDrafts(): void
    {
        $drafts = [];
        $memberAll = [];
        $memberLocations = [];
        $people = Membership::query()
            ->with(['role', 'assignedLocations'])
            ->whereIn('status', [MembershipStatus::Active->value, MembershipStatus::Invited->value])
            ->get();

        foreach ($people as $membership) {
            $drafts[$membership->id] = $membership->role->slug;
            $memberAll[$membership->id] = ! $membership->locations_restricted;
            $memberLocations[$membership->id] = $membership->assignedLocations
                ->pluck('location_id')
                ->map(fn (mixed $id): string => (string) $id)
                ->values()
                ->all();
        }

        $this->drafts = $drafts;
        $this->memberAll = $memberAll;
        $this->memberLocations = $memberLocations;
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, Role>
     */
    private function roleOptions(array $slugs): Collection
    {
        $order = array_flip($slugs);

        return Role::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->sortBy(fn (Role $role): int => $order[$role->slug] ?? 99)
            ->values();
    }

    private function clearMessages(): void
    {
        $this->flash = null;
        $this->problem = null;
        $this->resetErrorBag();
    }

    private function throttled(): bool
    {
        if (! RateLimiter::tooManyAttempts($this->inviteKey(), 10)) {
            return false;
        }

        $this->problem = 'Wait a minute before sending another invitation.';
        $this->flash = null;

        return true;
    }

    private function hitInviteLimit(): void
    {
        RateLimiter::hit($this->inviteKey(), 60);
    }

    private function inviteKey(): string
    {
        $user = $this->currentUser();

        return 'team-invite|'.$user->id;
    }
}
