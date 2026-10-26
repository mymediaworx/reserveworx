<?php

namespace Tests\Feature\Catalog;

use App\Livewire\Staff\Setup\ServiceDetail;
use App\Models\Asset;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PriceRule;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GroupPricingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_group_price_replaces_the_open_rate_for_that_party(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $small = $this->hold($service, 'small', 2, '2026-06-15T14:00:00Z', '2026-06-15T16:00:00Z');
        $large = $this->hold($service, 'large', 8, '2026-06-15T18:00:00Z', '2026-06-15T20:00:00Z');

        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Boat',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $created->assertJsonPath('data.min_participants', null)
            ->assertJsonPath('data.max_participants', null);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Group of 6 to 10',
            'kind' => 'base',
            'amount_cents' => 7_000,
            'min_participants' => 6,
            'max_participants' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.min_participants', 6)
            ->assertJsonPath('data.max_participants', 10);

        $smallPrice = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$small.'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $largePrice = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$large.'/pricing'))
            ->assertOk()
            ->json('data.payload');

        $this->assertSame(10_000, $smallPrice['services_cents']);
        $this->assertSame([], $smallPrice['party_bands']);
        $this->assertSame(7_000, $largePrice['services_cents']);
        $this->assertSame(6, $largePrice['party_bands'][0]['min_participants']);
        $this->assertSame(10, $largePrice['party_bands'][0]['max_participants']);
        $this->assertSame(7_000, $largePrice['party_bands'][0]['amount_cents']);
    }

    public function test_a_party_size_price_rejects_a_broken_range_or_a_clock_window(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $url = $this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices');

        $this->postJson($url, [
            'name' => 'No minimum',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'max_participants' => 8,
        ])->assertStatus(422)->assertJsonValidationErrors(['min_participants']);

        $this->postJson($url, [
            'name' => 'Upside down',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'min_participants' => 8,
            'max_participants' => 4,
        ])->assertStatus(422)->assertJsonValidationErrors(['max_participants']);

        $this->postJson($url, [
            'name' => 'Peak group',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'min_participants' => 4,
            'local_start' => '10:00',
            'local_end' => '14:00',
        ])->assertStatus(422)->assertJsonValidationErrors(['min_participants']);

        $this->assertSame(0, PriceRule::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->count());
    }

    public function test_staff_cannot_add_a_group_price_and_another_workspace_cannot_see_it(): void
    {
        [$alpha, $alphaService] = $this->shop('alpha.test');
        [$beta, $betaService] = $this->shop('beta.test');
        $staff = $this->workspace('staff.test', 'staff');
        app(TenantManager::class)->set($staff['tenant']);
        $staffService = Service::query()->create([
            'tenant_id' => $staff['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $staff['tenant']->id])->id,
            'name' => 'Staff paddle',
            'kind' => 'rental',
        ]);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($alpha);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$alphaService->id.'/prices'), [
            'name' => 'Group of 8 or more',
            'kind' => 'base',
            'amount_cents' => 6_000,
            'min_participants' => 8,
        ])->assertCreated();

        $this->actingAsWorkspace($staff);
        $this->postJson($this->tenantUrl('staff.test', '/api/v1/services/'.$staffService->id.'/prices'), [
            'name' => 'Staff group',
            'kind' => 'base',
            'amount_cents' => 1_000,
            'min_participants' => 4,
        ])->assertForbidden();
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$alphaService->id.'/prices'), [
            'name' => 'Stolen',
            'kind' => 'base',
            'amount_cents' => 1,
            'min_participants' => 4,
        ])->assertForbidden();

        $this->actingAsWorkspace($beta);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$alphaService->id.'/prices'), [
            'name' => 'Stolen',
            'kind' => 'base',
            'amount_cents' => 1,
            'min_participants' => 4,
        ])->assertForbidden();
        $this->postJson($this->tenantUrl('beta.test', '/api/v1/services/'.$alphaService->id.'/prices'), [
            'name' => 'Stolen',
            'kind' => 'base',
            'amount_cents' => 1,
            'min_participants' => 4,
        ])->assertNotFound();

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(0, PriceRule::query()->count());
        app(TenantManager::class)->forget();

        $this->assertSame(1, PriceRule::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->where('min_participants', 8)
            ->count());
        $this->assertSame(0, PriceRule::withoutGlobalScope(TenantScope::class)
            ->where('service_id', $betaService->id)
            ->count());
    }

    public function test_the_service_page_saves_a_party_size_and_hides_it_from_other_workspaces(): void
    {
        [$workspace, $service] = $this->shop();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(ServiceDetail::class, ['service' => $service->id])
            ->set('priceName', 'Group of 6 to 10')
            ->set('priceKind', 'base')
            ->set('priceAmount', '70')
            ->set('priceMinParty', '6')
            ->set('priceMaxParty', '10')
            ->call('addPrice')
            ->assertHasNoErrors()
            ->assertSee('6–10 people')
            ->assertSee('Party from');

        $rule = PriceRule::query()->firstOrFail();
        $this->assertSame(7_000, $rule->amount_cents);
        $this->assertSame(6, $rule->min_participants);
        $this->assertSame(10, $rule->max_participants);

        Livewire::test(ServiceDetail::class, ['service' => $service->id])
            ->set('priceName', 'No floor')
            ->set('priceKind', 'base')
            ->set('priceAmount', '10')
            ->set('priceMaxParty', '8')
            ->call('addPrice')
            ->assertHasErrors('priceMinParty');

        $staff = $this->workspace('staff.test', 'staff');
        app(TenantManager::class)->set($staff['tenant']);
        $this->actingAs($staff['user']);
        $staffLocation = Location::factory()->create(['tenant_id' => $staff['tenant']->id]);
        $staffService = Service::query()->create([
            'tenant_id' => $staff['tenant']->id,
            'location_id' => $staffLocation->id,
            'name' => 'Staff paddle',
            'kind' => 'rental',
        ]);

        Livewire::test(ServiceDetail::class, ['service' => $staffService->id])
            ->assertDontSee('Add price')
            ->set('priceName', 'Staff group')
            ->set('priceAmount', '10')
            ->set('priceMinParty', '4')
            ->call('addPrice')
            ->assertForbidden();

        Livewire::test(ServiceDetail::class, ['service' => $service->id])
            ->assertNotFound();
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function shop(string $host = 'dock.test'): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service];
    }

    private function hold(Service $service, string $suffix, int $participants, string $starts, string $ends): string
    {
        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => $participants,
            'idempotency_key' => 'group-'.$suffix.'-'.$service->id,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertCreated()->json('data.id');
    }
}
