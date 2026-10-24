<?php

namespace App\Services\Workspaces;

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\Access;
use Illuminate\Support\Collection;

/**
 * Workspaces one login can open. Portfolio totals use owned businesses only.
 * Switching lists every active membership, because the role on the destination
 * is what the permission checks will use.
 */
final class WorkspaceDirectory
{
    public function __construct(private readonly Access $access) {}

    public function isOwner(User $user, string $tenantId): bool
    {
        $membership = $this->access->membership($user, $tenantId);

        return $membership?->role?->slug === 'owner';
    }

    /**
     * Active or onboarding businesses this user owns, by name.
     *
     * @return Collection<int, Tenant>
     */
    public function owned(User $user): Collection
    {
        return $this->memberships($user)
            ->filter(fn (Membership $membership): bool => $membership->role?->slug === 'owner')
            ->map(fn (Membership $membership): ?Tenant => $membership->tenant)
            ->filter(fn (?Tenant $tenant): bool => $tenant instanceof Tenant && $this->operable($tenant))
            ->unique(fn (Tenant $tenant): string => $tenant->id)
            ->sortBy(fn (Tenant $tenant): string => $tenant->name)
            ->values();
    }

    public function verifiedHostname(Tenant $tenant): ?string
    {
        $domain = $tenant->domains()
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->first();

        return $domain instanceof Domain ? $domain->hostname : null;
    }

    /**
     * @return array{owner: bool, targets: list<array{id: string, name: string, role: string, hostname: string|null}>}
     */
    public function navigation(User $user, Tenant $current): array
    {
        $rows = $this->memberships($user);
        $owner = $rows->contains(
            fn (Membership $membership): bool => $membership->tenant_id === $current->id && $membership->role?->slug === 'owner',
        );

        $targets = [];

        foreach ($rows as $membership) {
            if ($membership->tenant_id === $current->id) {
                continue;
            }

            $tenant = $membership->tenant;

            if (! $tenant instanceof Tenant || ! $this->operable($tenant)) {
                continue;
            }

            $targets[] = [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'role' => $membership->role->name,
                'hostname' => $this->hostnameFromLoaded($tenant),
            ];
        }

        usort($targets, fn (array $left, array $right): int => $left['name'] <=> $right['name']);

        return [
            'owner' => $owner,
            'targets' => $targets,
        ];
    }

    private function operable(Tenant $tenant): bool
    {
        return in_array($tenant->status, [TenantStatus::Onboarding, TenantStatus::Active], true);
    }

    private function hostnameFromLoaded(Tenant $tenant): ?string
    {
        $verified = $tenant->domains->filter(fn (Domain $item): bool => $item->verified_at !== null);
        $primary = $verified->first(fn (Domain $item): bool => $item->is_primary);
        $domain = $primary instanceof Domain ? $primary : $verified->sortBy('hostname')->first();

        return $domain instanceof Domain ? $domain->hostname : null;
    }

    /**
     * @return Collection<int, Membership>
     */
    private function memberships(User $user): Collection
    {
        return $user->memberships()
            ->where('status', MembershipStatus::Active)
            ->with(['role', 'tenant.domains'])
            ->get();
    }
}
