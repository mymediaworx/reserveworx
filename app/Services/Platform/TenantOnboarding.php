<?php

namespace App\Services\Platform;

use App\Enums\LocationStatus;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Auth\PasswordResets;
use App\Support\Subdomain;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates a customer workspace idempotently: tenant, default location, owner
 * membership and verified {slug}.{apex} domain. Never touches prices, tax,
 * fees or refund terms; the settings row keeps the database defaults.
 */
final class TenantOnboarding
{
    public function __construct(
        private readonly WorkspaceProvisioner $provisioner,
        private readonly Subdomain $subdomains,
        private readonly PasswordResets $resets,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{tenant: Tenant, hostname: string, created: array<int, string>, link_sent: bool}
     */
    public function apply(string $slug, string $name, ?string $ownerEmail, string $timezone): array
    {
        $slug = $this->subdomains->normalize($slug);
        $name = trim($name);
        $ownerEmail = $ownerEmail === null || trim($ownerEmail) === '' ? null : strtolower(trim($ownerEmail));

        if (! $this->subdomains->isWellFormed($slug) || $this->subdomains->isReserved($slug)) {
            throw new InvalidArgumentException('slug is not valid or is reserved.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('name is required.');
        }
        if (! in_array($timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('timezone is not valid.');
        }
        if ($ownerEmail !== null && filter_var($ownerEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('owner email is not valid.');
        }

        $hostname = $this->subdomains->hostname($slug);
        $created = [];

        $existingDomain = Domain::withoutGlobalScope(TenantScope::class)->where('hostname', $hostname)->first();
        $tenant = Tenant::query()->where('slug', $slug)->first();
        if ($existingDomain instanceof Domain && (! $tenant instanceof Tenant || $existingDomain->tenant_id !== $tenant->id)) {
            $owner = Tenant::query()->whereKey($existingDomain->tenant_id)->first();
            $detail = $owner instanceof Tenant
                ? sprintf('slug=%s name="%s" status=%s created=%s', $owner->slug, $owner->name, $owner->status->value, (string) $owner->created_at)
                : 'slug=missing';
            throw new HostnameTaken('hostname belongs to another workspace: '.$detail);
        }

        $newOwner = null;

        DB::transaction(function () use (&$tenant, &$created, &$newOwner, $slug, $name, $ownerEmail, $timezone, $hostname): void {
            if (! $tenant instanceof Tenant) {
                $tenant = $this->provisioner->create($name, $slug, null, null);
                $created[] = 'tenant';
            }

            $hasLocation = Location::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->exists();
            if (! $hasLocation) {
                Location::withoutGlobalScope(TenantScope::class)->create([
                    'tenant_id' => $tenant->id,
                    'name' => $name,
                    'timezone' => $timezone,
                    'status' => LocationStatus::Active,
                ]);
                $created[] = 'location';
            }

            $domain = Domain::withoutGlobalScope(TenantScope::class)->where('hostname', $hostname)->first();
            if (! $domain instanceof Domain) {
                $domain = Domain::withoutGlobalScope(TenantScope::class)->create([
                    'tenant_id' => $tenant->id,
                    'hostname' => $hostname,
                    'type' => 'subdomain',
                    'is_primary' => true,
                    'verified_at' => now(),
                ]);
                $this->audit->log(action: 'domain.created', subject: $domain, properties: ['hostname' => $hostname, 'type' => 'subdomain'], tenantId: $tenant->id, useContextTenant: false);
                $created[] = 'domain';
            } elseif ($domain->verified_at === null) {
                $domain->forceFill(['verified_at' => now()])->save();
            }

            if ($ownerEmail === null) {
                return;
            }

            $user = User::query()->where('email', $ownerEmail)->first();
            if (! $user instanceof User) {
                // Nobody knows this password; the owner sets their own from the link.
                $user = User::query()->create([
                    'name' => $name.' owner',
                    'email' => $ownerEmail,
                    'password' => Str::random(64),
                ]);
                $created[] = 'owner_user';
            }

            $membership = Membership::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->first();
            if (! $membership instanceof Membership) {
                $this->provisioner->addOwner($tenant, $user, null, null);
                $created[] = 'owner_membership';
                $newOwner = $user;
            } elseif ($membership->status !== MembershipStatus::Active) {
                $role = Role::query()->where('slug', 'owner')->firstOrFail();
                $membership->forceFill(['role_id' => $role->id, 'status' => MembershipStatus::Active])->save();
            }
        });

        $linkSent = false;
        if ($newOwner instanceof User && $tenant->status !== TenantStatus::Terminated) {
            $this->resets->request($newOwner->email, fn (User $user): bool => true, 'https://'.$hostname.'/staff/reset-password');
            $linkSent = true;
        }

        return ['tenant' => $tenant, 'hostname' => $hostname, 'created' => $created, 'link_sent' => $linkSent];
    }
}
