<?php

namespace Tests\Feature\Web;

use App\Livewire\Platform\TenantDetail;
use App\Livewire\Platform\Tenants;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\PlatformAdministrator;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PlatformAdminTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        PlatformAdministrator::factory()->create(['user_id' => $user->id]);

        return $user;
    }

    public function test_a_platform_admin_signs_in_on_the_platform_host(): void
    {
        $admin = $this->admin();

        $this->post('http://app.reserveworx.com/platform/login', [
            'email' => $admin->email,
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/platform');

        $this->get('http://app.reserveworx.com/platform')->assertOk()->assertSee('Workspaces');
    }

    public function test_a_workspace_owner_cannot_sign_in_to_the_platform(): void
    {
        $alpha = $this->workspace('alpha.test');
        $alpha['user']->forceFill(['password' => 'correct-horse-battery'])->save();

        $this->post('http://app.reserveworx.com/platform/login', [
            'email' => $alpha['user']->email,
            'password' => 'correct-horse-battery',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->actingAs($alpha['user'])->get('http://app.reserveworx.com/platform')->assertForbidden();
    }

    public function test_platform_pages_do_not_exist_on_a_tenant_domain(): void
    {
        $this->workspace('alpha.test');
        $admin = $this->admin();

        $this->actingAs($admin)->get($this->tenantUrl('alpha.test', '/platform'))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/platform/login'))->assertNotFound();
    }

    public function test_guests_are_sent_to_platform_sign_in(): void
    {
        $this->get('http://app.reserveworx.com/platform')->assertRedirect('/platform/login');
    }

    public function test_onboard_a_workspace_end_to_end(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);

        Livewire::test(Tenants::class)
            ->set('name', 'Lakeside Paddle Co')
            ->assertSet('slug', 'lakeside-paddle-co')
            ->call('create')
            ->assertHasNoErrors();
        $tenant = Tenant::query()->where('slug', 'lakeside-paddle-co')->firstOrFail();
        $this->assertSame('onboarding', $tenant->status->value);
        $this->assertSame(300, TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->value('direct_fee_bps'));

        $detail = Livewire::test(TenantDetail::class, ['tenant' => $tenant->id])
            ->set('hostname', 'Lakeside.ReserveWorx.com')
            ->call('addDomain')
            ->assertHasNoErrors();
        $domain = Domain::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame('lakeside.reserveworx.com', $domain->hostname);
        $this->assertNull($domain->verified_at);

        $detail->call('verifyDomain', $domain->id);
        $this->assertNotNull($domain->fresh()?->verified_at);

        $detail->set('ownerName', 'Casey Lake')->set('ownerEmail', 'casey@example.com')->call('addOwner')->assertHasNoErrors();
        $password = $detail->get('newPassword');
        $this->assertIsString($password);
        $owner = User::query()->where('email', 'casey@example.com')->firstOrFail();
        $this->assertTrue(Hash::check($password, $owner->password));
        $this->assertSame('owner', Membership::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->firstOrFail()->role->slug);

        $detail->set('directPercent', '2.5')->set('directMinimum', '0.50')->call('saveFees')->assertHasNoErrors();
        $setting = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(250, $setting->direct_fee_bps);
        $this->assertSame(50, $setting->direct_fee_minimum_cents);
        $this->assertNull($setting->third_party_fee_minimum_cents);

        $detail->call('setStatus', 'active');
        $this->assertSame('active', $tenant->fresh()?->status->value);

        foreach (['tenant.created', 'domain.created', 'domain.verified', 'member.created', 'platform.fees_updated', 'tenant.status_changed'] as $action) {
            $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', $action)->count(), $action);
        }

        // The new owner can now sign in on the workspace's own address.
        $this->post('http://lakeside.reserveworx.com/staff/login', ['email' => 'casey@example.com', 'password' => $password])
            ->assertRedirect('/staff');
    }

    public function test_closing_a_workspace_for_good_is_refused(): void
    {
        $this->actingAs($this->admin());
        $alpha = $this->workspace('alpha.test', 'owner', ['status' => 'active']);

        Livewire::test(TenantDetail::class, ['tenant' => $alpha['tenant']->id])
            ->call('setStatus', 'terminated')
            ->assertSet('problem', 'Closing a workspace for good is refused until a data export exists.');

        $this->assertSame('active', $alpha['tenant']->fresh()?->status->value);
    }

    public function test_a_taken_or_platform_hostname_is_rejected(): void
    {
        $this->actingAs($this->admin());
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');

        Livewire::test(TenantDetail::class, ['tenant' => $beta['tenant']->id])
            ->set('hostname', 'alpha.test')->call('addDomain')->assertHasErrors('hostname')
            ->set('hostname', 'app.reserveworx.com')->call('addDomain')->assertHasErrors('hostname');
    }

    public function test_non_admins_cannot_use_platform_components(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->actingAs($alpha['user']);

        Livewire::test(Tenants::class)->assertForbidden();
    }
}
