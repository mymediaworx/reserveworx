<?php

namespace Tests\Feature\Platform;

use App\Models\AuditLog;
use App\Models\PlatformAdministrator;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TenantAdministrationTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_platform_administrator_can_onboard_a_tenant_and_the_action_is_audited(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $response = $this->postJson('http://localhost/api/v1/platform/tenants', [
            'name' => 'River Kayaks',
            'slug' => 'river-kayaks',
        ])->assertCreated();

        $tenantId = $response->json('data.id');

        $this->assertSame('onboarding', $response->json('data.status'));
        $this->assertDatabaseHas('tenant_settings', [
            'tenant_id' => $tenantId,
            'direct_fee_bps' => 300,
            'third_party_fee_bps' => 100,
            'fee_payer' => 'customer_paid',
            'hold_minutes' => 15,
            'ledger_bucket_minutes' => 15,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.created',
            'tenant_id' => $tenantId,
            'actor_user_id' => $admin->id,
        ]);

        $this->getJson('http://localhost/api/v1/platform/tenants/'.$tenantId)
            ->assertOk()
            ->assertJsonPath('data.slug', 'river-kayaks');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.tenant_viewed',
            'tenant_id' => $tenantId,
        ]);
    }

    public function test_termination_is_refused_until_export_exists(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $workspace = $this->workspace('alpha.test');
        Sanctum::actingAs($admin);

        $this->patchJson('http://localhost/api/v1/platform/tenants/'.$workspace['tenant']->id, [
            'status' => 'terminated',
        ])->assertStatus(409);

        $this->assertDatabaseHas('tenants', [
            'id' => $workspace['tenant']->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.termination_refused',
            'tenant_id' => $workspace['tenant']->id,
        ]);
    }

    public function test_only_a_platform_administrator_can_change_fee_rates(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->actingAsWorkspace($workspace);

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/settings'), [
            'direct_fee_bps' => 100,
        ])->assertUnprocessable();

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/settings'), [
            'fee_payer' => 'operator_absorbed',
            'hold_minutes' => 20,
        ])->assertOk()
            ->assertJsonPath('data.fee_payer', 'operator_absorbed')
            ->assertJsonPath('data.direct_fee_bps', 300);

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->patchJson('http://localhost/api/v1/platform/tenants/'.$workspace['tenant']->id.'/fees', [
            'direct_fee_bps' => 250,
            'third_party_fee_bps' => 100,
            'direct_fee_fixed_cents' => 0,
            'third_party_fee_fixed_cents' => 0,
            'direct_fee_minimum_cents' => null,
            'third_party_fee_minimum_cents' => null,
        ])->assertOk()->assertJsonPath('data.direct_fee_bps', 250);

        $this->assertSame(250, TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->value('direct_fee_bps'));

        $log = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'platform.fees_updated')->first();
        $this->assertNotNull($log);
        $this->assertSame(300, $log->properties['before']['direct_fee_bps']);
        $this->assertSame(250, $log->properties['after']['direct_fee_bps']);
    }

    public function test_domain_verification_is_an_audited_platform_attestation(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $workspace = $this->workspace('alpha.test');
        Sanctum::actingAs($admin);

        $created = $this->postJson('http://localhost/api/v1/platform/tenants/'.$workspace['tenant']->id.'/domains', [
            'hostname' => 'book.river-kayaks.test',
            'type' => 'custom',
        ])->assertCreated();

        $this->assertNull($created->json('data.verified_at'));

        $this->postJson('http://localhost/api/v1/platform/tenants/'.$workspace['tenant']->id.'/domains/'.$created->json('data.id').'/verify')
            ->assertOk()
            ->assertJsonPath('data.hostname', 'book.river-kayaks.test');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'domain.verified',
            'tenant_id' => $workspace['tenant']->id,
        ]);
    }

    public function test_grant_platform_admin_command_audits_the_grant(): void
    {
        $user = User::factory()->create(['email' => 'ops@example.com']);

        $this->artisan('reserveworx:grant-platform-admin', ['email' => 'ops@example.com'])
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->isPlatformAdmin());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'platform.admin_granted',
            'subject_id' => $user->id,
        ]);
    }
}
