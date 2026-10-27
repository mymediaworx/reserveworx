<?php

namespace Tests\Feature\Platform;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Enums\TenantStatus;
use App\Livewire\Platform\Demonstration;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TenantDemonstrationTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_two_demo_workspaces_are_shown_apart_from_a_third(): void
    {
        $harbor = $this->demo('harbor-demo', 'Harbor Demo', 'harbor-demo.reserveworx.test');
        $inland = $this->demo('inland-demo', 'Inland Demo', 'inland-demo.reserveworx.test');
        $this->service($inland);
        $harborService = $this->service($harbor);
        $this->booking($harbor, $harborService, 'ada@harbor-only.test');
        $other = $this->workspace('other.test', 'owner', [
            'name' => 'Other Marina',
            'slug' => 'other-marina',
        ]);
        $otherService = $this->service($other['tenant']);
        $this->booking($other['tenant'], $otherService, 'ada@other-marina.test');
        $admin = $this->admin();
        $audits = AuditLog::query()->count();

        $this->artisan('reserveworx:demo')
            ->expectsOutputToContain('harbor-demo=present name=Harbor Demo status=active hostname=harbor-demo.reserveworx.test locations=1 services=1 bookings=1 isolated=yes')
            ->expectsOutputToContain('inland-demo=present name=Inland Demo status=active hostname=inland-demo.reserveworx.test locations=1 services=1 bookings=0 isolated=yes')
            ->expectsOutputToContain('shown=yes')
            ->doesntExpectOutputToContain('ada@harbor-only.test')
            ->doesntExpectOutputToContain('ada@other-marina.test')
            ->doesntExpectOutputToContain('hidden-unverified')
            ->doesntExpectOutputToContain('Other Marina')
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/demo')
            ->assertOk()
            ->assertSee('Multi-tenant demonstration')
            ->assertSee('Shown')
            ->assertSee('data-shown="yes"', false)
            ->assertSee('Both demo workspaces are loaded.')
            ->assertSee('data-shop="harbor-demo"', false)
            ->assertSee('data-shop="inland-demo"', false)
            ->assertSee('data-bookings="1"', false)
            ->assertSee('data-bookings="0"', false)
            ->assertSee('data-isolated="yes"', false)
            ->assertSee('harbor-demo.reserveworx.test')
            ->assertSee('inland-demo.reserveworx.test')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('/platform/demo', false)
            ->assertDontSee('ada@harbor-only.test')
            ->assertDontSee('ada@other-marina.test')
            ->assertDontSee('hidden-unverified')
            ->assertDontSee('Other Marina')
            ->assertDontSee('not a host');

        Livewire::actingAs($admin)->test(Demonstration::class)
            ->assertSee('Harbor Demo')
            ->assertSee('Inland Demo')
            ->assertDontSee('Other Marina')
            ->assertDontSee('ada@harbor-only.test');

        $this->assertUnchanged($audits);
        $this->assertSame(3, Tenant::query()->count());
    }

    public function test_a_missing_demo_workspace_is_not_created(): void
    {
        $harbor = $this->demo('harbor-demo', 'Harbor Demo', 'harbor-demo.reserveworx.test');
        $service = $this->service($harbor);
        $this->booking($harbor, $service, 'ada@harbor-only.test');
        $admin = $this->admin();
        $audits = AuditLog::query()->count();

        $this->artisan('reserveworx:demo')
            ->expectsOutputToContain('harbor-demo=present name=Harbor Demo status=active hostname=harbor-demo.reserveworx.test locations=1 services=1 bookings=1 isolated=no')
            ->expectsOutputToContain('inland-demo=missing')
            ->expectsOutputToContain('shown=no')
            ->doesntExpectOutputToContain('ada@harbor-only.test')
            ->assertFailed();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/demo')
            ->assertOk()
            ->assertSee('Not shown')
            ->assertSee('data-shown="no"', false)
            ->assertSee('data-shop="inland-demo"', false)
            ->assertSee('data-state="missing"', false)
            ->assertSee('This demo workspace is not loaded. Opening this page does not create it.')
            ->assertSee('The other demo workspace is not loaded, so these records were not compared.')
            ->assertDontSee('ada@harbor-only.test');

        $this->assertUnchanged($audits);
        $this->assertNull(Tenant::query()->where('slug', 'inland-demo')->first());
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_demonstration(): void
    {
        $this->get('http://app.reserveworx.com/platform/demo')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/demo')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(Demonstration::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($this->admin())
            ->get($this->tenantUrl('alpha.test', '/platform/demo'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/demo'))
            ->assertNotFound();
    }

    private function demo(string $slug, string $name, string $hostname): Tenant
    {
        $tenant = Tenant::factory()->create([
            'slug' => $slug,
            'name' => $name,
            'status' => TenantStatus::Active,
        ]);
        Domain::factory()->create([
            'tenant_id' => $tenant->id,
            'hostname' => $hostname,
        ]);
        Domain::factory()->unverified()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'hidden-unverified.'.$slug.'.test',
            'is_primary' => false,
        ]);
        Domain::factory()->create([
            'tenant_id' => $tenant->id,
            'hostname' => 'not a host '.$slug,
            'is_primary' => false,
        ]);

        return $tenant;
    }

    private function service(Tenant $tenant): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $tenant->slug.' dock',
        ]);
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $tenant->name.' rental',
            'kind' => ServiceKind::Rental,
        ]);
        app(TenantManager::class)->forget();

        return $service;
    }

    private function booking(Tenant $tenant, Service $service, string $email): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Ada',
            'email' => $email,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => CarbonImmutable::parse('2026-11-03 15:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-11-03 16:00:00', 'UTC'),
            'participants' => 1,
            'payload_hash' => hash('sha256', $email),
        ]);
        app(TenantManager::class)->forget();

        return $booking;
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        return $admin;
    }

    private function assertUnchanged(int $audits): void
    {
        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertSame(0, Payment::query()->withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::query()->withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::query()->withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFeeRefund::query()->withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PricingSnapshot::query()->withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PriceRule::query()->withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, LocationTaxRule::query()->withoutGlobalScope(TenantScope::class)->count());
    }
}
