<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform\Security;
use App\Models\GhlConnection;
use App\Models\Permission;
use App\Models\PlatformAdministrator;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\DependencyScanningCheck;
use App\Services\Platform\SecurityCheck;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
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
            ->expectsOutputToContain('session_host=pass')
            ->expectsOutputToContain('session_same_site=pass')
            ->expectsOutputToContain('framing=pass')
            ->expectsOutputToContain('audit_log=pass')
            ->expectsOutputToContain('roles=pass')
            ->expectsOutputToContain('dependabot=pass')
            ->expectsOutputToContain('composer_audit=pass')
            ->expectsOutputToContain('secure=yes')
            ->doesntExpectOutputToContain(SecurityCheck::PROBE_MARK)
            ->doesntExpectOutputToContain(self::KEPT_TOKEN)
            ->doesntExpectOutputToContain('.github/')
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
            ->assertSee('The session cookie stays on the current host.')
            ->assertSee('The session cookie is SameSite lax.')
            ->assertSee('has to stay on the current host')
            ->assertSee('Sign-in pages cannot be framed. The booking page can.')
            ->assertSee('The audit log table is present.')
            ->assertSee('Owner, manager, and staff match the role catalog.')
            ->assertSee('Dependabot watches Composer packages.')
            ->assertSee('CI runs composer audit.')
            ->assertSee('the role catalog, Dependabot, and composer audit in CI.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('/platform/security', false)
            ->assertDontSee(SecurityCheck::PROBE_MARK)
            ->assertDontSee(self::KEPT_TOKEN)
            ->assertDontSee('.github/dependabot.yml')
            ->assertDontSee('.github/workflows/ci.yml');

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
            ->expectsOutputToContain('session_host=pass')
            ->expectsOutputToContain('session_same_site=pass')
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

    public function test_a_session_cookie_shared_across_hosts_is_not_secure(): void
    {
        config([
            'session.domain' => '.example.test canary-host-secret',
            'session.same_site' => "none\ncanary-samesite-secret",
        ]);
        $before = $this->counts();

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('session_host=fail')
            ->expectsOutputToContain('session_same_site=fail')
            ->expectsOutputToContain('session_http_only=pass')
            ->expectsOutputToContain('secure=no')
            ->doesntExpectOutputToContain('canary-host-secret')
            ->doesntExpectOutputToContain('canary-samesite-secret')
            ->doesntExpectOutputToContain('.example.test')
            ->doesntExpectOutputToContain(SecurityCheck::PROBE_MARK)
            ->assertFailed();

        config([
            'session.domain' => '.example.test',
            'session.same_site' => 'none',
        ]);

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Not secure')
            ->assertSee('data-secure="no"', false)
            ->assertSee('The session cookie is not limited to the current host.')
            ->assertSee('The session cookie is not SameSite lax or strict.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertDontSee('The session cookie stays on the current host.')
            ->assertDontSee('The session cookie is SameSite lax.')
            ->assertDontSee('canary-host-secret')
            ->assertDontSee('canary-samesite-secret')
            ->assertDontSee('.example.test')
            ->assertDontSee(SecurityCheck::PROBE_MARK);

        $this->assertSame($before, $this->counts());
    }

    public function test_a_strict_host_only_cookie_is_secure(): void
    {
        config([
            'session.domain' => '',
            'session.same_site' => ' STRICT ',
        ]);
        $before = $this->counts();

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('session_host=pass')
            ->expectsOutputToContain('session_same_site=pass')
            ->expectsOutputToContain('secure=yes')
            ->doesntExpectOutputToContain(SecurityCheck::PROBE_MARK)
            ->assertSuccessful();

        config([
            'session.domain' => null,
            'session.same_site' => 'strict',
        ]);

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Secure')
            ->assertSee('The session cookie stays on the current host.')
            ->assertSee('The session cookie is SameSite strict.')
            ->assertDontSee(SecurityCheck::PROBE_MARK);

        $this->assertSame($before, $this->counts());
    }

    public function test_missing_dependabot_or_composer_audit_is_not_secure_and_file_text_stays_hidden(): void
    {
        $admin = $this->admin();
        $before = $this->counts();
        $this->app->instance(DependencyScanningCheck::class, new DependencyScanningCheck(
            dependabotReader: fn (): string => "version: 2\nupdates:\n  - package-ecosystem: npm\n    directory: /\n# canary-dependabot-secret\n",
            ciReader: fn (): string => "name: CI\njobs:\n  lint:\n    steps:\n      - run: vendor/bin/pint --test\n# canary-ci-audit-secret\n",
        ));

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('dependabot=fail')
            ->expectsOutputToContain('composer_audit=fail')
            ->expectsOutputToContain('roles=pass')
            ->expectsOutputToContain('secure=no')
            ->doesntExpectOutputToContain('canary-dependabot-secret')
            ->doesntExpectOutputToContain('canary-ci-audit-secret')
            ->doesntExpectOutputToContain('package-ecosystem')
            ->doesntExpectOutputToContain('.github/')
            ->doesntExpectOutputToContain(SecurityCheck::PROBE_MARK)
            ->assertFailed();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Not secure')
            ->assertSee('data-secure="no"', false)
            ->assertSee('Dependabot is not configured for Composer.')
            ->assertSee('CI does not run composer audit.')
            ->assertDontSee('Dependabot watches Composer packages.')
            ->assertDontSee('CI runs composer audit.')
            ->assertDontSee('canary-dependabot-secret')
            ->assertDontSee('canary-ci-audit-secret')
            ->assertDontSee('package-ecosystem')
            ->assertDontSee('.github/dependabot.yml')
            ->assertDontSee('.github/workflows/ci.yml')
            ->assertDontSee(SecurityCheck::PROBE_MARK);

        $this->assertSame($before, $this->counts());
    }

    public function test_a_thrown_dependency_scan_reader_is_not_secure_and_hides_the_exception(): void
    {
        $before = $this->counts();
        $this->app->instance(DependencyScanningCheck::class, new DependencyScanningCheck(
            dependabotReader: function (): string {
                throw new RuntimeException('canary-dependabot-throw-secret');
            },
            ciReader: function (): string {
                throw new RuntimeException('canary-ci-throw-secret');
            },
        ));

        $this->artisan('reserveworx:security')
            ->expectsOutputToContain('dependabot=fail')
            ->expectsOutputToContain('composer_audit=fail')
            ->expectsOutputToContain('secure=no')
            ->doesntExpectOutputToContain('canary-dependabot-throw-secret')
            ->doesntExpectOutputToContain('canary-ci-throw-secret')
            ->doesntExpectOutputToContain(SecurityCheck::PROBE_MARK)
            ->assertFailed();

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/security')
            ->assertOk()
            ->assertSee('Not secure')
            ->assertSee('Dependabot is not configured for Composer.')
            ->assertSee('CI does not run composer audit.')
            ->assertDontSee('canary-dependabot-throw-secret')
            ->assertDontSee('canary-ci-throw-secret')
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
