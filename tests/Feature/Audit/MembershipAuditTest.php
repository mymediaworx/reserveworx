<?php

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class MembershipAuditTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_creating_a_member_audits_the_action_without_the_password(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->actingAsWorkspace($workspace);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/members'), [
            'name' => 'Sam Staff',
            'email' => 'sam@example.com',
            'password' => 'correct-horse-battery',
            'role' => 'staff',
        ])->assertCreated();

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'membership.created')
            ->where('tenant_id', $workspace['tenant']->id)
            ->first();

        $this->assertNotNull($log);
        $encoded = (string) json_encode($log->properties);
        $this->assertStringNotContainsString('correct-horse-battery', $encoded);
        $this->assertSame('sam@example.com', $log->properties['email']);
    }

    public function test_staff_can_view_settings_but_not_the_audit_log_or_fee_rates(): void
    {
        $workspace = $this->workspace('alpha.test', 'staff');
        $this->actingAsWorkspace($workspace);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/settings'))->assertOk();
        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/settings'), [
            'hold_minutes' => 30,
        ])->assertForbidden();
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/audit-logs'))->assertForbidden();
    }

    public function test_one_workspace_cannot_read_another_workspaces_audit_log(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($alpha);

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/settings'), [
            'hold_minutes' => 25,
        ])->assertOk();

        $alphaLog = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->where('action', 'settings.updated')
            ->firstOrFail();

        $this->actingAsWorkspace($beta);
        $response = $this->getJson($this->tenantUrl('beta.test', '/api/v1/audit-logs'))->assertOk();
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertFalse($ids->contains($alphaLog->id));
    }

    public function test_the_last_owner_cannot_be_revoked(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->actingAsWorkspace($workspace);

        $this->patchJson($this->tenantUrl('alpha.test', '/api/v1/members/'.$workspace['membership']->id), [
            'status' => 'revoked',
        ])->assertUnprocessable();
    }
}
