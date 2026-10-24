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
            ['GET', '/api/v1/branding'],
            ['PATCH', '/api/v1/branding'],
            ['GET', '/api/v1/branding/logo'],
            ['GET', '/api/v1/notification-settings'],
            ['PATCH', '/api/v1/notification-settings'],
            ['GET', '/api/v1/booking-reminder-offsets'],
            ['PATCH', '/api/v1/booking-reminder-offsets'],
            ['GET', '/api/v1/members'],
            ['POST', '/api/v1/members'],
            ['GET', '/api/v1/members/'.$beta['membership']->id],
            ['PATCH', '/api/v1/members/'.$beta['membership']->id],
            ['GET', '/api/v1/audit-logs'],
            ['GET', '/api/v1/locations'],
            ['POST', '/api/v1/locations'],
            ['GET', '/api/v1/resource-categories'],
            ['POST', '/api/v1/resource-categories'],
            ['GET', '/api/v1/assets'],
            ['POST', '/api/v1/assets'],
            ['GET', '/api/v1/pools'],
            ['POST', '/api/v1/pools'],
            ['GET', '/api/v1/schedules'],
            ['POST', '/api/v1/schedules'],
            ['GET', '/api/v1/blackouts'],
            ['POST', '/api/v1/blackouts'],
            ['GET', '/api/v1/services'],
            ['POST', '/api/v1/services'],
            ['GET', '/api/v1/availability'],
            ['GET', '/api/v1/customers'],
            ['POST', '/api/v1/customers/'.$beta['membership']->id.'/exports'],
            ['GET', '/api/v1/customers/'.$beta['membership']->id.'/exports/'.$beta['membership']->id],
            ['POST', '/api/v1/customers/'.$beta['membership']->id.'/deletion'],
            ['GET', '/api/v1/bookings'],
            ['POST', '/api/v1/bookings'],
            ['GET', '/api/v1/calendar'],
            ['GET', '/api/v1/departures'],
            ['POST', '/api/v1/departures'],
            ['GET', '/api/v1/waitlist'],
            ['POST', '/api/v1/waitlist'],
            ['POST', '/api/v1/promo-codes'],
            ['POST', '/api/v1/services/'.$beta['membership']->id.'/prices'],
            ['GET', '/api/v1/services/'.$beta['membership']->id.'/variants'],
            ['POST', '/api/v1/services/'.$beta['membership']->id.'/variants'],
            ['GET', '/api/v1/services/'.$beta['membership']->id.'/variants/'.$beta['membership']->id],
            ['PATCH', '/api/v1/services/'.$beta['membership']->id.'/variants/'.$beta['membership']->id],
            ['DELETE', '/api/v1/services/'.$beta['membership']->id.'/variants/'.$beta['membership']->id],
            ['GET', '/api/v1/add-ons'],
            ['POST', '/api/v1/add-ons'],
            ['GET', '/api/v1/add-ons/'.$beta['membership']->id],
            ['PATCH', '/api/v1/add-ons/'.$beta['membership']->id],
            ['DELETE', '/api/v1/add-ons/'.$beta['membership']->id],
            ['GET', '/api/v1/operator-fees'],
            ['POST', '/api/v1/operator-fees'],
            ['GET', '/api/v1/operator-fees/'.$beta['membership']->id],
            ['PATCH', '/api/v1/operator-fees/'.$beta['membership']->id],
            ['DELETE', '/api/v1/operator-fees/'.$beta['membership']->id],
            ['POST', '/api/v1/stripe/account'],
            ['GET', '/api/v1/fee-ledger'],
            ['GET', '/api/v1/reports/today'],
            ['GET', '/api/v1/reports/today/export'],
            ['GET', '/api/v1/reports/summaries'],
            ['GET', '/api/v1/reports/summaries/export'],
            ['GET', '/api/v1/reports/revenue'],
            ['GET', '/api/v1/reports/revenue/export'],
            ['GET', '/api/v1/reports/customers'],
            ['GET', '/api/v1/reports/customers/export'],
            ['GET', '/api/v1/reports/tours'],
            ['GET', '/api/v1/reports/tours/export'],
            ['GET', '/api/v1/reports/operations'],
            ['GET', '/api/v1/reports/operations/export'],
            ['GET', '/api/v1/integrations/outbox'],
            ['GET', '/api/v1/widget-tokens'],
            ['POST', '/api/v1/widget-tokens'],
            ['GET', '/api/v1/maintenance-windows'],
            ['POST', '/api/v1/maintenance-windows'],
            ['GET', '/api/v1/assets/'.$beta['membership']->id.'/maintenance-records'],
            ['POST', '/api/v1/assets/'.$beta['membership']->id.'/maintenance-records'],
            ['POST', '/api/v1/assets/'.$beta['membership']->id.'/retire'],
            ['GET', '/api/v1/notification-hooks'],
            ['POST', '/api/v1/bookings/'.$beta['membership']->id.'/party-size'],
            ['POST', '/api/v1/bookings/'.$beta['membership']->id.'/participants'],
            ['GET', '/api/v1/bookings/'.$beta['membership']->id.'/incidents'],
            ['GET', '/api/v1/bookings/'.$beta['membership']->id.'/returns'],
            ['POST', '/api/v1/bookings/'.$beta['membership']->id.'/returns'],
            ['GET', '/api/v1/bookings/'.$beta['membership']->id.'/participants'],
            ['POST', '/api/v1/bookings/'.$beta['membership']->id.'/participants'],
            ['DELETE', '/api/v1/bookings/'.$beta['membership']->id.'/participants/'.$beta['membership']->id],
            ['POST', '/api/v1/waiver-versions'],
            ['GET', '/api/v1/bookings/'.$beta['membership']->id.'/waivers/'.$beta['membership']->id],
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
        $this->getJson('http://localhost/api/v1/platform/fee-revenue')->assertForbidden();
        $this->getJson('http://localhost/api/v1/platform/fee-reconciliation?from=2026-06-01&to=2026-06-02')->assertForbidden();
        $this->postJson('http://localhost/api/v1/platform/fees/01ARZ3NDEKTSV4RRFFQ69G5FAV/refunds', [
            'amount_cents' => 100,
            'reason' => 'A member cannot refund a booking fee.',
        ])->assertForbidden();
    }
}
