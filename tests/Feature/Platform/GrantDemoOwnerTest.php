<?php

namespace Tests\Feature\Platform;

use App\Enums\MembershipStatus;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GrantDemoOwnerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_existing_user_becomes_the_harbor_demo_owner_and_a_real_workspace_is_refused(): void
    {
        $user = User::factory()->create(['email' => 'michael@brandworx.ai', 'name' => 'Michael']);
        $harbor = Tenant::factory()->create(['slug' => 'harbor-demo', 'name' => 'Harbor Demo']);
        $real = Tenant::factory()->create(['slug' => 'real-marina', 'name' => 'Real Marina']);

        $this->artisan('reserveworx:grant-demo-owner', [
            'email' => 'Michael@BrandWorx.ai',
            'slug' => 'harbor-demo',
        ])->assertSuccessful()->expectsOutputToContain('owner_membership=granted email=michael@brandworx.ai tenant=harbor-demo');

        $membership = Membership::query()->withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $harbor->id)
            ->where('user_id', $user->id)
            ->first();
        $this->assertInstanceOf(Membership::class, $membership);
        $this->assertSame(MembershipStatus::Active, $membership->status);
        $this->assertSame('owner', $membership->role->slug);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'membership.created',
            'tenant_id' => $harbor->id,
        ]);

        $this->artisan('reserveworx:grant-demo-owner', [
            'email' => 'michael@brandworx.ai',
            'slug' => 'harbor-demo',
        ])->assertSuccessful();
        $this->assertSame(1, Membership::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $harbor->id)->count());

        $membership->forceFill(['status' => MembershipStatus::Revoked])->save();
        $this->artisan('reserveworx:grant-demo-owner', [
            'email' => 'michael@brandworx.ai',
            'slug' => 'harbor-demo',
        ])->assertSuccessful();
        $this->assertSame(MembershipStatus::Active, $membership->fresh()?->status);

        $this->artisan('reserveworx:grant-demo-owner', [
            'email' => 'michael@brandworx.ai',
            'slug' => 'real-marina',
        ])->assertFailed();
        $this->assertSame(0, Membership::query()->withoutGlobalScope(TenantScope::class)->where('tenant_id', $real->id)->count());

        $this->artisan('reserveworx:grant-demo-owner', [
            'email' => 'missing@brandworx.ai',
            'slug' => 'harbor-demo',
        ])->assertFailed();
        $this->assertNull(User::query()->where('email', 'missing@brandworx.ai')->first());
    }
}
