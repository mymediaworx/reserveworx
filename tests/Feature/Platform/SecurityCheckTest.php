<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform\Security;
use App\Models\GhlConnection;
use App\Models\Permission;
use App\Models\PlatformAdministrator;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\SecurityCheck;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class SecurityCheckTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const KEPT_TOKEN = 'kept-ghl-token-value';

    public function test_a_secure_server_passes_without_showing_a_token_or_keeping_a_row(): void
    {
        $admin = $this->admin();
        $workspace = $this->workspace('alpha.test');
        GhlConnection::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => 'loc_kept',
            'private_integration_token' => self::KEPT_TOKEN,
        ]);
        $before = $this->counts();
        $roleRows = Role::query()->count();
        $roleLinks = DB::table('role_permission')->count();

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('ghl_token=pass')
            ->expectsOutputToContain('webhook_secret=pass')
            ->expectsOutputToContain('card_columns=pass')
            ->expectsOutputToContain('csrf=pass')
            ->expectsOutputToContain('session_http_only=pass')
            ->expectsOutputToContain('audit_log=pass')
            ->expectsOutputToContain('roles=pass')
            ->expectsOutputToContain('secure=yes')
            ->doesntExpectOutputToContain(SecurityCheck::PROBE_MARK)
            ->doesntExpectOutputToContain(self::KEPT_TOKEN)
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Security')
            ->assertSee('Secure')
            ->assertSee('data-secure="yes"', false)
            ->assertSee('The GoHighLevel private token is encrypted at rest.')
            ->assertSee('The outbound webhook secret is encrypted at rest.')
            ->assertSee('Payments and connected accounts do not store a card number or a CVC.')
            ->assertSee('Form posts use Laravel\'s CSRF check.')
            ->assertSee('The session cookie is HTTP-only.')
            ->assertSee('The audit log table is present.')
            ->assertSee('Owner, manager, and staff match the role catalog.')
            ->assertSee('and the role catalog.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('/platform/security', false)
            ->assertDontSee(SecurityCheck::PROBE_MARK)
            ->assertDontSee(self::KEPT_TOKEN);

        Livewire::actingAs($admin)->test(Security::class)
            ->assertSee('The GoHighLevel private token is encrypted at rest.')
            ->assertDontSee(SecurityCheck::PROBE_MARK)
            ->assertDontSee(self::KEPT_TOKEN);

        $this->assertSame($before, $this->counts());
        $this->assertSame($roleRows, Role::query()->count());
        $this->assertSame($roleLinks, DB::table('role_permission')->count());
        $this->assertSame(self::KEPT_TOKEN, GhlConnection::withoutGlobalScope(TenantScope::class)->where('location_id', 'loc_kept')->firstOrFail()->private_integration_token);
        $this->assertSame(0, Tenant::query()->where('slug', 'like', 'security-check-%')->count());
    }

    public function test_roles_that_do_not_match_the_catalog_are_not_secure(): void
    {
        $admin = $this->admin();
        $owner = Role::query()->where('slug', 'owner')->firstOrFail();
        $permission = Permission::query()->where('slug', 'settings.view')->firstOrFail();
        $owner->permissions()->detach($permission->id);
        $before = $this->counts();
        $roleRows = Role::query()->count();
        $roleLinks = DB::table('role_permission')->count();

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('roles=fail')
            ->expectsOutputToContain('secure=no')
            ->doesntExpectOutputToContain('settings.view')
            ->doesntExpectOutputToContain(SecurityCheck::PROBE_MARK)
            ->assertFailed();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Not secure')
            ->assertSee('data-secure="no"', false)
            ->assertSee('The stored roles do not match the role catalog.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertDontSee('Owner, manager, and staff match the role catalog.')
            ->assertDontSee('settings.view')
            ->assertDontSee(SecurityCheck::PROBE_MARK);

        Livewire::actingAs($admin)->test(Security::class)
            ->assertSee('The stored roles do not match the role catalog.')
            ->assertDontSee(SecurityCheck::PROBE_MARK);

        $this->assertSame($before, $this->counts());
        $this->assertSame($roleRows, Role::query()->count());
        $this->assertSame($roleLinks, DB::table('role_permission')->count());
        $this->assertSame(0, DB::table('role_permission')->where('role_id', $owner->id)->where('permission_id', $permission->id)->count());
    }

    public function test_a_cookie_that_javascript_can_read_is_not_secure(): void
    {
        config(['session.http_only' => false]);
        $before = $this->counts();

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('ghl_token=pass')
            ->expectsOutputToContain('session_http_only=fail')
            ->expectsOutputToContain('roles=pass')
            ->expectsOutputToContain('secure=no')
            ->doesntExpectOutputToContain(SecurityCheck::PROBE_MARK)
            ->assertFailed();

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Not secure')
            ->assertSee('data-secure="no"', false)
            ->assertSee('The session cookie is not HTTP-only.')
            ->assertSee('The GoHighLevel private token is encrypted at rest.')
            ->assertDontSee(SecurityCheck::PROBE_MARK);

        $this->assertSame($before, $this->counts());
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_check(): void
    {
        $this->get('http://app.reserveworx.com/platform/security')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/security')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(Security::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($this->admin())
            ->get($this->tenantUrl('alpha.test', '/platform/security'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/security'))
            ->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        return $admin;
    }

    /**
     * @return array{tenants: int, connections: int, endpoints: int, payments: int, refunds: int, fees: int, fee_refunds: int, prices: int, taxes: int, snapshots: int, audits: int}
     */
    private function counts(): array
    {
        return [
            'tenants' => DB::table('tenants')->count(),
            'connections' => DB::table('ghl_connections')->count(),
            'endpoints' => DB::table('webhook_endpoints')->count(),
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
