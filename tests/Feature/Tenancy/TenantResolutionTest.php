<?php

namespace Tests\Feature\Tenancy;

use App\Models\Domain;
use App\Models\Membership;
use App\Models\TenantSetting;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TenantResolutionTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_verified_domain_and_membership_resolve_the_workspace(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->actingAsWorkspace($workspace);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/tenant'))
            ->assertOk()
            ->assertJsonPath('data.id', $workspace['tenant']->id);

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $workspace['tenant']->id);
    }

    public function test_an_unverified_domain_does_not_resolve(): void
    {
        $workspace = $this->workspace('pending.test');
        $workspace['domain']->forceFill(['verified_at' => null])->save();
        $this->actingAsWorkspace($workspace);

        $this->getJson($this->tenantUrl('pending.test', '/api/v1/tenant'))
            ->assertNotFound();
    }

    public function test_an_unknown_host_is_rejected(): void
    {
        $this->getJson('http://unknown.test/api/v1/tenant')->assertNotFound();
    }

    public function test_the_platform_host_does_not_imply_a_tenant(): void
    {
        $this->getJson('http://app.reserveworx.com/api/v1/tenant')->assertNotFound();
    }

    public function test_a_suspended_workspace_is_unavailable(): void
    {
        $workspace = $this->workspace('suspended.test', tenantAttributes: ['status' => 'suspended']);
        $this->actingAsWorkspace($workspace);

        $this->getJson($this->tenantUrl('suspended.test', '/api/v1/tenant'))
            ->assertForbidden();
    }

    public function test_a_client_supplied_tenant_id_must_match_the_domain(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($alpha);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/settings'), [
            'X-Tenant-Id' => $alpha['tenant']->id,
        ])->assertOk();

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/settings?tenant_id='.$beta['tenant']->id))
            ->assertForbidden();

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/settings'), [
            'tenant_id' => $beta['tenant']->id,
            'hold_minutes' => 20,
        ])->assertForbidden();

        $this->assertSame(
            15,
            TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $alpha['tenant']->id)->value('hold_minutes'),
        );
    }

    public function test_the_same_user_sees_only_the_workspace_for_the_domain(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');

        $beta['membership']->delete();
        $beta['membership'] = Membership::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);

        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->update(['hold_minutes' => 20]);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->update(['hold_minutes' => 40]);

        $this->actingAsWorkspace($alpha);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/settings'))
            ->assertOk()
            ->assertJsonPath('data.hold_minutes', 20);

        $this->getJson($this->tenantUrl('beta.test', '/api/v1/settings'))
            ->assertOk()
            ->assertJsonPath('data.hold_minutes', 40);
    }

    public function test_queries_without_tenant_context_fail_closed(): void
    {
        $workspace = $this->workspace('alpha.test');

        $this->assertSame(0, TenantSetting::query()->count());
        $this->assertSame(1, TenantSetting::withoutGlobalScope(TenantScope::class)->count());
        $this->assertNotNull(Domain::withoutGlobalScope(TenantScope::class)->find($workspace['domain']->id));
    }
}
