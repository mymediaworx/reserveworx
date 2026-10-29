<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform\Isolation;
use App\Models\Customer;
use App\Models\PlatformAdministrator;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\CrossTenantIsolationCheck;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CrossTenantIsolationTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const KEPT_EMAIL = 'kept-customer@example.com';

    public function test_a_platform_admin_sees_an_isolated_server_and_the_sample_is_thrown_away(): void
    {
        $admin = $this->admin();
        $workspace = $this->workspace('alpha.test');
        app(TenantManager::class)->set($workspace['tenant']);
        Customer::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Kept Customer',
            'email' => self::KEPT_EMAIL,
        ]);
        app(TenantManager::class)->forget();
        $before = $this->counts();

        $this->artisan('reserveworx:isolation')
            ->expectsOutputToContain('other_tenant=pass')
            ->expectsOutputToContain('no_context=pass')
            ->expectsOutputToContain('isolated=yes')
            ->doesntExpectOutputToContain(CrossTenantIsolationCheck::PROBE_MARK)
            ->doesntExpectOutputToContain(self::KEPT_EMAIL)
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/isolation')
            ->assertOk()
            ->assertSee('Isolation')
            ->assertSee('Isolated')
            ->assertSee('data-isolated="yes"', false)
            ->assertSee('Another workspace cannot read this workspace\'s customer.')
            ->assertSee('A request with no workspace cannot read a customer.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('href="/platform/isolation"', false)
            ->assertDontSee(CrossTenantIsolationCheck::PROBE_MARK)
            ->assertDontSee(self::KEPT_EMAIL);

        Livewire::actingAs($admin)->test(Isolation::class)
            ->assertSee('Another workspace cannot read this workspace\'s customer.')
            ->assertDontSee(CrossTenantIsolationCheck::PROBE_MARK)
            ->assertDontSee(self::KEPT_EMAIL);

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/help')
            ->assertOk()
            ->assertSee('href="/platform/isolation"', false)
            ->assertSee('The same lines are printed by reserveworx:isolation.')
            ->assertDontSee(self::KEPT_EMAIL);

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertOk()
            ->assertDontSee('href="/platform/isolation"', false)
            ->assertDontSee('reserveworx:isolation');

        $this->assertSame($before, $this->counts());
        $this->assertSame(self::KEPT_EMAIL, Customer::withoutGlobalScope(TenantScope::class)->where('email', self::KEPT_EMAIL)->firstOrFail()->email);
        $this->assertSame(0, Tenant::query()->where('slug', 'like', 'isolation-%')->count());
        $this->assertSame(0, Customer::withoutGlobalScope(TenantScope::class)->where('email', 'like', CrossTenantIsolationCheck::PROBE_MARK.'%')->count());
    }

    public function test_a_leaked_customer_fails_the_report_and_keeps_no_row(): void
    {
        $before = $this->counts();
        $built = app(CrossTenantIsolationCheck::class)->build(
            otherTenantSees: fn (string $email): bool => true,
            noContextSees: fn (string $email): bool => true,
        );

        $this->assertFalse($built['isolated']);
        $this->assertSame('fail', $built['checks'][0]['state']);
        $this->assertSame('fail', $built['checks'][1]['state']);
        $this->assertSame('Another workspace can read this workspace\'s customer.', $built['checks'][0]['detail']);
        $this->assertSame('A request with no workspace can read a customer.', $built['checks'][1]['detail']);
        $this->assertStringNotContainsString(CrossTenantIsolationCheck::PROBE_MARK, $built['checks'][0]['detail']);
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, Customer::withoutGlobalScope(TenantScope::class)->where('email', 'like', CrossTenantIsolationCheck::PROBE_MARK.'%')->count());
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_check(): void
    {
        $this->get('http://app.reserveworx.com/platform/isolation')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/isolation')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(Isolation::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($this->admin())
            ->get($this->tenantUrl('alpha.test', '/platform/isolation'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/isolation'))
            ->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        return $admin;
    }

    /**
     * @return array{tenants: int, customers: int, payments: int, refunds: int, fees: int, fee_refunds: int, prices: int, taxes: int, snapshots: int, audits: int}
     */
    private function counts(): array
    {
        return [
            'tenants' => DB::table('tenants')->count(),
            'customers' => DB::table('customers')->count(),
            'payments' => DB::table('payments')->count(),
            'refunds' => DB::table('refunds')->count(),
            'fees' => DB::table('platform_fees')->count(),
            'fee_refunds' => DB::table('platform_fee_refunds')->count(),
            'prices' => DB::table('price_rules')->count(),
            'taxes' => DB::table('location_tax_rules')->count(),
            'snapshots' => DB::table('pricing_snapshots')->count(),
            'audits' => DB::table('audit_logs')->count(),
        ];
    }
}
