<?php

namespace App\Services\Team;

use App\Enums\MembershipStatus;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TeamInvite;
use App\Services\AuditLogger;
use App\Services\Tenancy\WorkspaceOrigin;
use App\Tenancy\Access;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\StaffLocationScope;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Invites and membership changes for one workspace. The signed link is built
 * on that workspace's verified domain. Only the hash of the token is stored.
 */
final class WorkspaceTeam
{
    public const INVITE_DAYS = 7;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly Access $access,
        private readonly RoleGuard $roles,
        private readonly TenantManager $tenants,
        private readonly WorkspaceOrigin $origins,
    ) {}

    /**
     * @param  list<string>  $locationIds
     */
    public function invite(User $actor, string $email, string $roleSlug, Request $request, bool $allLocations = true, array $locationIds = []): InviteResult
    {
        $tenant = $this->tenant();
        $this->assertManager($actor, $tenant->id);
        $email = strtolower(trim($email));
        $this->roles->assertCanAssign($actor, $tenant->id, $roleSlug);
        $ids = $this->validatedLocationIds($allLocations, $locationIds);

        if ($email === strtolower($actor->email)) {
            throw ValidationException::withMessages([
                'email' => 'You are already on this team.',
            ]);
        }

        $role = $this->role($roleSlug);

        if ($this->origins->baseUrl($tenant->id) === null) {
            throw ValidationException::withMessages([
                'email' => 'This workspace has no verified address to send the invitation from.',
            ]);
        }

        $plain = $this->plainToken();
        $expires = now()->addDays(self::INVITE_DAYS);

        try {
            $membership = DB::transaction(function () use ($actor, $email, $role, $tenant, $plain, $expires, $request, $allLocations, $ids): Membership {
                $existing = User::query()->where('email', $email)->first();
                $user = $existing;

                if (! $user instanceof User) {
                    $user = User::query()->create([
                        'name' => $this->placeholderName($email),
                        'email' => $email,
                        'password' => Str::random(40),
                    ]);
                    $user->forceFill(['password_set_at' => null])->save();
                }

                $membership = Membership::query()->where('user_id', $user->id)->first();

                if ($membership instanceof Membership && $membership->status === MembershipStatus::Active) {
                    throw ValidationException::withMessages([
                        'email' => 'That person is already a member of this workspace.',
                    ]);
                }

                if ($membership instanceof Membership && $membership->status === MembershipStatus::Invited) {
                    throw ValidationException::withMessages([
                        'email' => 'An invitation is already waiting for that email. Resend it below.',
                    ]);
                }

                if ($membership instanceof Membership) {
                    $this->roles->assertCanTouch($actor, $membership);
                    $membership->role_id = $role->id;
                    $membership->status = MembershipStatus::Invited;
                } else {
                    $membership = new Membership([
                        'tenant_id' => $tenant->id,
                        'user_id' => $user->id,
                        'role_id' => $role->id,
                        'status' => MembershipStatus::Invited,
                    ]);
                }

                $membership->invite_token_hash = hash('sha256', $plain);
                $membership->invite_expires_at = $expires;
                $membership->save();

                if (! $allLocations) {
                    $this->storeLocations($membership, false, $ids);
                }

                $properties = [
                    'email' => $email,
                    'role' => $role->slug,
                    'existing_user' => $existing instanceof User,
                ];

                if (! $allLocations) {
                    $properties['location_ids'] = $ids;
                }

                $this->audit->log(
                    action: 'membership.invited',
                    subject: $membership,
                    properties: $properties,
                    actor: $actor,
                    request: $request,
                );

                return $membership->load(['user', 'role']);
            });
        } catch (QueryException $exception) {
            if (! $this->isDuplicate($exception)) {
                throw $exception;
            }

            throw ValidationException::withMessages([
                'email' => 'That person is already a member of this workspace.',
            ]);
        }

        return new InviteResult($membership, $this->deliver($membership, $plain, $tenant->name));
    }

    public function resend(User $actor, Membership $membership, Request $request): InviteResult
    {
        $tenant = $this->tenant();
        $this->assertSameWorkspace($membership, $tenant);
        $this->assertManager($actor, $tenant->id);
        $this->roles->assertCanTouch($actor, $membership);
        $this->pending($membership);

        $plain = $this->plainToken();
        $membership->invite_token_hash = hash('sha256', $plain);
        $membership->invite_expires_at = now()->addDays(self::INVITE_DAYS);
        $membership->save();
        $membership->loadMissing(['user', 'role']);

        $this->audit->log(
            action: 'membership.invite_resent',
            subject: $membership,
            properties: [
                'email' => $membership->user?->email,
                'role' => $membership->role?->slug,
            ],
            actor: $actor,
            request: $request,
        );

        return new InviteResult($membership, $this->deliver($membership, $plain, $tenant->name));
    }

    public function revokeInvite(User $actor, Membership $membership, Request $request): Membership
    {
        $tenant = $this->tenant();
        $this->assertSameWorkspace($membership, $tenant);
        $this->assertManager($actor, $tenant->id);
        $this->roles->assertCanTouch($actor, $membership);
        $this->pending($membership);
        $membership->loadMissing(['user', 'role']);
        $email = $membership->user?->email;
        $role = $membership->role?->slug;

        $membership->status = MembershipStatus::Revoked;
        $membership->invite_token_hash = null;
        $membership->invite_expires_at = null;
        $membership->save();

        $this->audit->log(
            action: 'membership.invite_revoked',
            subject: $membership,
            properties: [
                'email' => $email,
                'role' => $role,
            ],
            actor: $actor,
            request: $request,
        );

        return $membership;
    }

    /**
     * @param  list<string>  $locationIds
     */
    public function assignLocations(User $actor, Membership $membership, bool $allLocations, array $locationIds, Request $request): Membership
    {
        $tenant = $this->tenant();
        $this->assertSameWorkspace($membership, $tenant);
        $this->assertManager($actor, $tenant->id);
        $this->roles->assertCanTouch($actor, $membership);

        if (! in_array($membership->status, [MembershipStatus::Active, MembershipStatus::Invited], true)) {
            throw ValidationException::withMessages([
                'team' => 'That person is not on this team.',
            ]);
        }

        $ids = $this->validatedLocationIds($allLocations, $locationIds);
        $beforeIds = $this->storedLocationIds($membership);
        $beforeAll = ! $membership->locations_restricted;

        $this->storeLocations($membership, $allLocations, $ids);

        $same = $beforeAll === $allLocations && $beforeIds === $ids;

        if (! $same) {
            $membership->loadMissing('user');
            $this->audit->log(
                action: 'membership.locations_updated',
                subject: $membership,
                properties: [
                    'email' => $membership->user?->email,
                    'all_locations' => $allLocations,
                    'location_ids' => $allLocations ? [] : $ids,
                ],
                actor: $actor,
                request: $request,
            );
        }

        return $membership;
    }

    public function changeRole(User $actor, Membership $membership, string $roleSlug, Request $request): Membership
    {
        $tenant = $this->tenant();
        $this->assertSameWorkspace($membership, $tenant);
        $this->assertManager($actor, $tenant->id);
        $this->roles->assertCanTouch($actor, $membership);
        $this->roles->assertCanAssign($actor, $tenant->id, $roleSlug);
        $role = $this->role($roleSlug);
        $membership->loadMissing('role');

        if (! in_array($membership->status, [MembershipStatus::Active, MembershipStatus::Invited], true)) {
            throw ValidationException::withMessages([
                'team' => 'That person is not on this team.',
            ]);
        }

        if ($membership->role?->slug === $role->slug) {
            return $membership;
        }

        $this->roles->assertKeepsAnOwner($membership, $role->slug, $membership->status);
        $before = $membership->role?->slug;
        $membership->role_id = $role->id;
        $membership->save();
        $membership->unsetRelation('role');
        $membership->load('role');

        $this->audit->log(
            action: 'membership.updated',
            subject: $membership,
            properties: [
                'email' => $membership->user?->email,
                'before_role' => $before,
                'after_role' => $role->slug,
                'status' => $membership->status->value,
            ],
            actor: $actor,
            request: $request,
        );

        return $membership;
    }

    public function remove(User $actor, Membership $membership, Request $request): Membership
    {
        $tenant = $this->tenant();
        $this->assertSameWorkspace($membership, $tenant);
        $this->assertManager($actor, $tenant->id);
        $this->roles->assertCanTouch($actor, $membership);
        $membership->loadMissing(['user', 'role']);

        if ($membership->status !== MembershipStatus::Active) {
            throw ValidationException::withMessages([
                'team' => 'That person is not an active member.',
            ]);
        }

        $this->roles->assertKeepsAnOwner($membership, (string) $membership->role?->slug, MembershipStatus::Revoked);
        $before = $membership->status->value;
        $email = $membership->user?->email;
        $role = $membership->role?->slug;
        $membership->status = MembershipStatus::Revoked;
        $membership->invite_token_hash = null;
        $membership->invite_expires_at = null;
        $membership->save();

        $this->audit->log(
            action: 'membership.revoked',
            subject: $membership,
            properties: [
                'email' => $email,
                'role' => $role,
                'before_status' => $before,
                'after_status' => $membership->status->value,
            ],
            actor: $actor,
            request: $request,
        );

        return $membership;
    }

    public function invitationIsOpen(Membership $membership, string $token): bool
    {
        $tenant = $this->tenants->get();

        if (! $tenant instanceof Tenant || $tenant->id !== $membership->tenant_id) {
            return false;
        }

        if ($membership->status !== MembershipStatus::Invited) {
            return false;
        }

        if ($membership->invite_expires_at === null || $membership->invite_expires_at->isPast()) {
            return false;
        }

        $hash = $membership->invite_token_hash;

        if (! is_string($hash) || strlen($token) !== 64 || strlen($hash) !== 64) {
            return false;
        }

        return hash_equals($hash, hash('sha256', $token));
    }

    public function accept(Membership $membership, string $token, Request $request, ?string $name, ?string $password): User
    {
        if (! $this->invitationIsOpen($membership, $token)) {
            throw new InviteClosed('This invitation is no longer valid.');
        }

        $session = $request->user();

        if ($session instanceof User && $session->id !== $membership->user_id) {
            throw ValidationException::withMessages([
                'email' => 'Sign out of the other account before accepting this invitation.',
            ]);
        }

        /** @var User $account */
        $account = DB::transaction(function () use ($membership, $token, $request, $name, $password): User {
            $locked = Membership::query()->whereKey($membership->id)->lockForUpdate()->first();

            if (! $locked instanceof Membership || ! $this->invitationIsOpen($locked, $token)) {
                throw new InviteClosed('This invitation is no longer valid.');
            }

            $locked->load(['user', 'role']);
            $user = $locked->user;

            if (! $user instanceof User) {
                throw new InviteClosed('This invitation is no longer valid.');
            }

            $existing = $user->password_set_at !== null;

            if (! $existing) {
                $user->forceFill([
                    'name' => $name,
                    'password' => $password,
                    'password_set_at' => now(),
                    'email_verified_at' => $user->email_verified_at ?? now(),
                ])->save();
            } elseif ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $locked->status = MembershipStatus::Active;
            $locked->invite_token_hash = null;
            $locked->invite_expires_at = null;
            $locked->save();

            $this->audit->log(
                action: 'membership.accepted',
                subject: $locked,
                properties: [
                    'email' => $user->email,
                    'role' => $locked->role?->slug,
                    'existing_user' => $existing,
                ],
                actor: $user,
                request: $request,
            );

            return $user;
        });

        return $account;
    }

    public function needsPassword(Membership $membership): bool
    {
        $membership->loadMissing('user');

        return $membership->user?->password_set_at === null;
    }

    private function deliver(Membership $membership, string $plain, string $businessName): bool
    {
        $membership->loadMissing(['user', 'role']);
        $user = $membership->user;

        if (! $user instanceof User) {
            return false;
        }

        try {
            $user->notify(new TeamInvite(
                $this->signedUrl($membership, $plain),
                $businessName,
                $membership->role->name,
                self::INVITE_DAYS,
            ));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function signedUrl(Membership $membership, string $plain): string
    {
        $origin = $this->origins->baseUrl($membership->tenant_id);

        if ($origin === null || $membership->invite_expires_at === null) {
            throw ValidationException::withMessages([
                'email' => 'This workspace has no verified address to send the invitation from.',
            ]);
        }

        return $this->origins->generate(
            $origin,
            fn (): string => URL::temporarySignedRoute(
                'staff.invite.show',
                $membership->invite_expires_at,
                [
                    'membership' => $membership->id,
                    'token' => $plain,
                ],
            ),
        );
    }

    /**
     * @param  list<mixed>  $locationIds
     * @return list<string>
     */
    private function validatedLocationIds(bool $allLocations, array $locationIds): array
    {
        if ($allLocations) {
            return [];
        }

        $ids = [];

        foreach ($locationIds as $id) {
            if (is_string($id) && $id !== '') {
                $ids[$id] = $id;
            }
        }

        $ids = array_values($ids);
        sort($ids);

        if ($ids === []) {
            throw ValidationException::withMessages([
                'locations' => 'Choose at least one location.',
            ]);
        }

        $found = Location::query()
            ->withoutGlobalScope(StaffLocationScope::class)
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();

        if (count($found) !== count($ids)) {
            throw ValidationException::withMessages([
                'locations' => 'Choose a location in this workspace.',
            ]);
        }

        return $ids;
    }

    /**
     * @param  list<string>  $ids
     */
    private function storeLocations(Membership $membership, bool $allLocations, array $ids): void
    {
        $membership->locations_restricted = ! $allLocations;
        $membership->save();
        MembershipLocation::query()->where('membership_id', $membership->id)->delete();

        if (! $allLocations) {
            foreach ($ids as $id) {
                MembershipLocation::query()->create([
                    'tenant_id' => $membership->tenant_id,
                    'membership_id' => $membership->id,
                    'location_id' => $id,
                ]);
            }
        }

        app(StaffLocationAccess::class)->forget();
    }

    /**
     * @return list<string>
     */
    private function storedLocationIds(Membership $membership): array
    {
        if (! $membership->locations_restricted) {
            return [];
        }

        $ids = MembershipLocation::query()
            ->where('membership_id', $membership->id)
            ->orderBy('location_id')
            ->pluck('location_id')
            ->all();
        $ids = array_values(array_map(strval(...), $ids));
        sort($ids);

        return $ids;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenants->get();
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function assertSameWorkspace(Membership $membership, Tenant $tenant): void
    {
        abort_unless($membership->tenant_id === $tenant->id, 404);
    }

    private function assertManager(User $actor, string $tenantId): void
    {
        if ($this->access->allows($actor, $tenantId, 'members.manage')) {
            return;
        }

        throw new AuthorizationException('You cannot manage this team.');
    }

    private function pending(Membership $membership): void
    {
        if ($membership->status === MembershipStatus::Invited) {
            return;
        }

        throw ValidationException::withMessages([
            'team' => 'That invitation is no longer pending.',
        ]);
    }

    private function role(string $slug): Role
    {
        if (! in_array($slug, ['owner', 'manager', 'staff'], true)) {
            throw ValidationException::withMessages([
                'role' => 'Choose owner, manager, or staff.',
            ]);
        }

        $role = Role::query()->where('slug', $slug)->first();

        if (! $role instanceof Role) {
            throw ValidationException::withMessages([
                'role' => 'Choose owner, manager, or staff.',
            ]);
        }

        return $role;
    }

    private function plainToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    private function placeholderName(string $email): string
    {
        $local = strstr($email, '@', true);
        $local = is_string($local) ? trim(str_replace(['.', '_', '-', '+'], ' ', $local)) : '';

        return mb_substr($local === '' ? 'Teammate' : $local, 0, 120);
    }

    private function isDuplicate(QueryException $exception): bool
    {
        $state = $exception->errorInfo[0] ?? null;

        return $state === '23000' || (string) $exception->getCode() === '23000';
    }
}
