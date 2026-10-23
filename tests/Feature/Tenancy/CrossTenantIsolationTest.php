<?php

namespace Tests\Feature\Tenancy;

use App\Models\Membership;
use App\Models\PlatformAdministrator;
use App\Models\TenantSetting;
use App\Models\User;
use App\Policies\TenantSettingPolicy;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CrossTenantIsolationTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_every_tenant_endpoint_rejects_another_workspaces_member(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($alpha);

        $endpoints = [
            ['GET', '/api/v1/tenant'],
            ['GET', '/api/v1/settings'],
            ['PATCH', '/api/v1/settings'],
            ['GET', '/api/v1/members'],
            ['POST', '/api/v1/members'],
            ['GET', '/api/v1/members/'.$beta['membership']->id],
            ['PATCH', '/api/v1/members/'.$beta['membership']->id],
            ['GET', '/api/v1/audit-logs'],
        ];

        foreach ($endpoints as [$method, $path]) {
            $response = $this->json($method, $this->tenantUrl('beta.test', $path), [
                'hold_minutes' => 30,
                'name' => 'Pat Example',
                'email' => 'pat@example.com',
                'password' => 'correct-horse-battery',
                'role' => 'staff',
                'status' => 'revoked',
            ]);

            $this->assertContains(
                $response->status(),
                [403, 404],
                $method.' '.$path.' returned '.$response->status(),
            );
        }

        $this->assertSame(0, Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->where('user_id', $alpha['user']->id)
            ->count());
    }

    public function test_a_member_id_from_another_workspace_is_not_found_on_the_callers_domain(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($alpha);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/members/'.$beta['membership']->id))
            ->assertNotFound();
    }

    public function test_a_policy_denies_a_setting_loaded_outside_the_current_scope(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');

        app(TenantManager::class)->set($alpha['tenant']);

        $foreign = TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->firstOrFail();

        $this->assertFalse(app(TenantSettingPolicy::class)->view($alpha['user'], $foreign));
        $this->assertFalse(app(TenantSettingPolicy::class)->update($alpha['user'], $foreign));
    }

    public function test_a_revoked_member_cannot_read_the_workspace(): void
    {
        $workspace = $this->workspace('alpha.test');
        $workspace['membership']->forceFill(['status' => 'revoked'])->save();
        $this->actingAsWorkspace($workspace);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/settings'))->assertForbidden();
    }

    public function test_a_platform_administrator_without_membership_cannot_use_tenant_routes(): void
    {
        $workspace = $this->workspace('alpha.test');
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/settings'))->assertForbidden();
    }

    public function test_a_tenant_member_cannot_call_platform_routes(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->actingAsWorkspace($workspace);

        $this->getJson('http://localhost/api/v1/platform/tenants')->assertForbidden();
        $this->getJson('http://localhost/api/v1/platform/tenants/'.$workspace['tenant']->id)->assertForbidden();
    }
}
