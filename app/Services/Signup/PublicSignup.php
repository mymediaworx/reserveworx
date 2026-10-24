<?php

namespace App\Services\Signup;

use App\Models\Domain;
use App\Models\SignupAcceptance;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Platform\WorkspaceProvisioner;
use App\Support\SignupTerms;
use App\Support\Subdomain;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Opens a public signup the same way the platform opens a workspace: onboarding
 * status and the database fee defaults. Fee fields are not accepted.
 */
final class PublicSignup
{
    public function __construct(
        private readonly WorkspaceProvisioner $provisioner,
        private readonly SignupVerification $verification,
        private readonly Subdomain $subdomains,
        private readonly AuditLogger $audit,
    ) {}

    public function register(
        string $businessName,
        string $slug,
        string $ownerName,
        string $email,
        string $password,
        Request $request,
    ): Tenant {
        $tenant = DB::transaction(function () use ($businessName, $slug, $ownerName, $email, $password, $request): Tenant {
            $user = User::query()->create([
                'name' => $ownerName,
                'email' => $email,
                'password' => $password,
            ]);

            $tenant = $this->provisioner->create($businessName, $slug, $user, $request);
            $this->provisioner->addOwner($tenant, $user, $user, $request);

            $hostname = $this->subdomains->hostname($slug);
            $acceptedAt = now();
            $domain = Domain::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenant->id,
                'hostname' => $hostname,
                'type' => 'subdomain',
                'is_primary' => true,
                'verified_at' => $acceptedAt,
            ]);

            $this->audit->log(
                action: 'domain.created',
                subject: $domain,
                properties: ['hostname' => $hostname, 'type' => 'subdomain'],
                tenantId: $tenant->id,
                actor: $user,
                request: $request,
                useContextTenant: false,
            );
            $this->audit->log(
                action: 'domain.verified',
                subject: $domain,
                properties: ['hostname' => $hostname, 'method' => 'platform_subdomain'],
                tenantId: $tenant->id,
                actor: $user,
                request: $request,
                useContextTenant: false,
            );

            $acceptance = SignupAcceptance::withoutGlobalScope(TenantScope::class)->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'terms_version' => SignupTerms::VERSION,
                'accepted_at' => $acceptedAt,
            ]);

            $this->audit->log(
                action: 'signup.terms_accepted',
                subject: $acceptance,
                properties: [
                    'terms_version' => SignupTerms::VERSION,
                    'accepted_at' => $acceptedAt->toIso8601String(),
                ],
                tenantId: $tenant->id,
                actor: $user,
                request: $request,
                useContextTenant: false,
            );

            return $tenant;
        });

        $owner = User::query()->where('email', strtolower($email))->first();

        if ($owner instanceof User) {
            $this->verification->send($owner, $tenant);
        }

        return $tenant;
    }
}
