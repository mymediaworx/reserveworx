<?php

namespace Tests\Feature\Web;

use App\Enums\Industry;
use App\Livewire\Platform\Handbook as PlatformHandbook;
use App\Livewire\Staff\Handbook;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class UserHandbookTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_sees_the_pages_they_can_open_and_nothing_is_written(): void
    {
        $owner = $this->signedIn(name: 'Alpha <script>alert(1)</script>');
        $this->workspace('beta.test', 'owner', ['name' => 'Beta Only Kayaks']);
        $before = $this->counts();

        $page = $this->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertOk()
            ->assertSee('Handbook')
            ->assertSee('Alpha &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('How to use')
            ->assertSee('does not change a booking, a price, a payment, a tax, a fee, or a refund')
            ->assertSee('Each boat')
            ->assertSee('Boats &amp; gear', false)
            ->assertSee('href="/staff/bookings"', false)
            ->assertSee('href="/staff/setup"', false)
            ->assertSee('href="/staff/import"', false)
            ->assertSee('href="/staff/team"', false)
            ->assertSee('href="/staff/customers"', false)
            ->assertSee('href="/staff/audit"', false)
            ->assertSee('href="/staff/portfolio"', false)
            ->assertSee('href="/staff/businesses/new"', false)
            ->assertSee('All businesses')
            ->assertSee('Add a business')
            ->assertSee('href="/admin/integrations"', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertDontSee('Beta Only Kayaks')
            ->assertDontSee('<script>', false)
            ->assertDontSee('3%');

        $this->assertSame($before, $this->counts());

        $this->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('href="/staff/help"', false);

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('Handbook')
            ->assertSee('href="/staff/help"', false);

        $this->keep($owner);
        Livewire::test(Handbook::class)
            ->assertSee('Daily work')
            ->assertSee('Audit log')
            ->assertDontSee('Beta Only Kayaks');

        $html = (string) $page->getContent();
        preg_match_all('/<li class="handbook-topic">\s*<a href="([^"]+)"/', $html, $matches);
        $handbookLinks = $matches[1];
        $this->assertContains('/staff/maintenance', $handbookLinks);
        $this->assertContains('/staff/audit', $handbookLinks);
        $this->assertContains('/staff/portfolio', $handbookLinks);
        $this->assertContains('/staff/businesses/new', $handbookLinks);
        foreach ($handbookLinks as $href) {
            $this->assertTrue(str_starts_with($href, '/'));
            $this->assertStringNotContainsString('://', $href);
        }
    }

    public function test_staff_do_not_see_owner_only_pages(): void
    {
        $staff = $this->signedIn('staff');

        $this->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertOk()
            ->assertSee('Check in')
            ->assertSee('Settings')
            ->assertSee('href="/staff/bookings"', false)
            ->assertDontSee('Setup guide')
            ->assertDontSee('Customer records')
            ->assertDontSee('Audit log')
            ->assertDontSee('>Import<', false)
            ->assertDontSee('href="/staff/team"', false)
            ->assertDontSee('href="/staff/customers"', false)
            ->assertDontSee('href="/staff/audit"', false)
            ->assertDontSee('All businesses')
            ->assertDontSee('Add a business')
            ->assertDontSee('href="/staff/portfolio"', false)
            ->assertDontSee('href="/staff/businesses/new"', false);

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('href="/staff/help"', false)
            ->assertDontSee('href="/staff/audit"', false);

        $this->keep($staff);
        Livewire::test(Handbook::class)->assertDontSee('Audit log');
    }

    public function test_a_location_limited_owner_does_not_see_privacy_pages(): void
    {
        $owner = $this->signedIn();
        $home = Location::factory()->create([
            'tenant_id' => $owner['tenant']->id,
            'name' => 'Home dock',
        ]);
        $owner['membership']->locations_restricted = true;
        $owner['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $owner['tenant']->id,
            'membership_id' => $owner['membership']->id,
            'location_id' => $home->id,
        ]);
        app(StaffLocationAccess::class)->forget();

        $this->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertOk()
            ->assertSee('Handbook')
            ->assertSee('href="/staff/bookings"', false)
            ->assertDontSee('Customer records')
            ->assertDontSee('Audit log')
            ->assertDontSee('href="/staff/customers"', false)
            ->assertDontSee('href="/staff/audit"', false)
            ->assertSee('All businesses')
            ->assertSee('Add a business')
            ->assertSee('href="/staff/portfolio"', false)
            ->assertSee('href="/staff/businesses/new"', false);
    }

    public function test_a_manager_does_not_see_owner_business_pages(): void
    {
        $this->signedIn('manager');

        $this->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertOk()
            ->assertSee('Setup guide')
            ->assertSee('href="/staff/setup"', false)
            ->assertDontSee('All businesses')
            ->assertDontSee('Add a business')
            ->assertDontSee('href="/staff/portfolio"', false)
            ->assertDontSee('href="/staff/businesses/new"', false);
    }

    public function test_a_spa_uses_its_own_word_and_another_workspace_is_refused(): void
    {
        $spa = $this->workspace('spa.test', 'owner', [
            'name' => 'Quiet Spa',
            'industry' => Industry::SpasWellness->value,
        ]);
        app(TenantManager::class)->set($spa['tenant']);
        $this->actingAs($spa['user']);

        $this->get($this->tenantUrl('spa.test', '/staff/help'))
            ->assertOk()
            ->assertSee('Each room')
            ->assertSee('Rooms')
            ->assertDontSee('Each boat');

        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('spa.test', '/staff/help'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_staff_login(): void
    {
        $this->workspace('alpha.test');

        $this->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertRedirect('/staff/login');
    }

    public function test_only_a_platform_admin_on_the_platform_host_reads_the_admin_handbook(): void
    {
        Tenant::factory()->create(['name' => 'Secret Harbor Name']);
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $before = $this->counts();

        $this->get('http://app.reserveworx.com/platform/help')
            ->assertRedirect('/platform/login');

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/help')
            ->assertOk()
            ->assertSee('Admin handbook')
            ->assertSee('does not change a workspace, a price, a payment, a tax, a fee, or a refund')
            ->assertSee('href="/platform/readiness"', false)
            ->assertSee('href="/platform/performance"', false)
            ->assertSee('href="/platform/upgrade"', false)
            ->assertSee('The page does not run a deploy.')
            ->assertSee('href="/platform"', false)
            ->assertSee('The private token is typed once and is not shown again.')
            ->assertSee('This handbook does not change them.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertDontSee('Secret Harbor Name')
            ->assertDontSee('3%');

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform')
            ->assertOk()
            ->assertSee('href="/platform/help"', false);

        $this->assertSame($before, $this->counts());

        Livewire::actingAs($admin)->test(PlatformHandbook::class)
            ->assertSee('Readiness')
            ->assertSee('Performance')
            ->assertSee('Upgrade')
            ->assertDontSee('Secret Harbor Name');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/help')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(PlatformHandbook::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($admin)
            ->get($this->tenantUrl('alpha.test', '/platform/help'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/help'))
            ->assertNotFound();
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(string $role = 'owner', string $name = 'Alpha Kayaks'): array
    {
        $workspace = $this->workspace('alpha.test', $role, ['name' => $name]);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function keep(array $workspace): void
    {
        app(TenantManager::class)->forget();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
    }

    /**
     * @return array{audits: int, payments: int, refunds: int, fees: int, prices: int, taxes: int}
     */
    private function counts(): array
    {
        return [
            'audits' => AuditLog::withoutGlobalScope(TenantScope::class)->count(),
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'prices' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }
}
