<?php

namespace App\Console\Commands;

use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantScope;
use Illuminate\Console\Command;

class GrantDemoOwner extends Command
{
    protected $signature = 'reserveworx:grant-demo-owner {email} {slug}';

    protected $description = 'Add an existing user as an owner of a demo workspace';

    public function handle(AuditLogger $audit): int
    {
        $email = strtolower((string) $this->argument('email'));
        $slug = (string) $this->argument('slug');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('email is not valid.');

            return self::FAILURE;
        }

        if (! in_array($slug, ['harbor-demo', 'inland-demo'], true)) {
            $this->error('That workspace is not a demo tenant.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user instanceof User) {
            $this->error('No user exists with that email. This command does not create users.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant instanceof Tenant || in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Terminated], true)) {
            $this->error('workspace is unavailable.');

            return self::FAILURE;
        }

        $role = Role::query()->where('slug', 'owner')->first();

        if (! $role instanceof Role) {
            $this->error('owner role is missing.');

            return self::FAILURE;
        }

        $membership = Membership::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $membership instanceof Membership) {
            $membership = Membership::query()->withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_id' => $role->id,
                'status' => MembershipStatus::Active,
            ]);
            $audit->log(
                action: 'membership.created',
                subject: $membership,
                properties: ['email' => $user->email, 'role' => 'owner'],
                tenantId: $tenant->id,
                useContextTenant: false,
            );
        } elseif ($membership->status !== MembershipStatus::Active || $membership->role_id !== $role->id) {
            $membership->forceFill([
                'role_id' => $role->id,
                'status' => MembershipStatus::Active,
            ])->save();
            $audit->log(
                action: 'membership.updated',
                subject: $membership,
                properties: ['email' => $user->email, 'role' => 'owner', 'status' => 'active'],
                tenantId: $tenant->id,
                useContextTenant: false,
            );
        }

        $this->info('owner_membership=granted email='.$user->email.' tenant='.$tenant->slug);

        return self::SUCCESS;
    }
}
