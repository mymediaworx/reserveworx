<?php

namespace Tests\Support;

use App\Models\Domain;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

trait CreatesWorkspace
{
    /**
     * @param  array<string, mixed>  $tenantAttributes
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    protected function workspace(string $host, string $role = 'owner', array $tenantAttributes = []): array
    {
        $tenant = Tenant::factory()->create($tenantAttributes);
        $domain = Domain::factory()->create([
            'tenant_id' => $tenant->id,
            'hostname' => $host,
        ]);
        $user = User::factory()->create();
        $membership = Membership::factory()->role($role)->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        return compact('tenant', 'domain', 'user', 'membership');
    }

    /**
     * @param  array{user: User}  $workspace
     */
    protected function actingAsWorkspace(array $workspace): void
    {
        Sanctum::actingAs($workspace['user']);
    }

    protected function tenantUrl(string $host, string $path): string
    {
        return 'http://'.$host.$path;
    }
}
