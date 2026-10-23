<?php

namespace Tests\Feature\Jobs;

use App\Models\TenantSetting;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\Support\CreatesWorkspace;
use Tests\Support\SetsHoldMinutesJob;
use Tests\TestCase;

class TenantAwareJobTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_job_updates_only_its_own_tenant_and_clears_context(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');

        Bus::dispatchSync(new SetsHoldMinutesJob($alpha['tenant']->id, 45));

        $this->assertSame(45, TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->value('hold_minutes'));
        $this->assertSame(15, TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->value('hold_minutes'));
        $this->assertNull(app(TenantManager::class)->id());
    }

    public function test_a_job_without_a_real_tenant_fails(): void
    {
        $this->expectException(TenantContextException::class);

        Bus::dispatchSync(new SetsHoldMinutesJob('01ARZ3NDEKTSV4RRFFQ69G5FAV', 20));
    }

    public function test_a_job_refuses_a_terminated_tenant(): void
    {
        $workspace = $this->workspace('alpha.test', tenantAttributes: ['status' => 'terminated']);

        $this->expectException(TenantContextException::class);

        Bus::dispatchSync(new SetsHoldMinutesJob($workspace['tenant']->id, 20));
    }
}
