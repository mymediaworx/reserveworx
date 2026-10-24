<?php

namespace Tests\Feature\Catalog;

use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ServiceVariantTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_settings_can_manage_variants_and_staff_can_only_read_them(): void
    {
        [$workspace, $service, $tandem, $category] = $this->shop();
        $this->actingAsWorkspace($workspace);

        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/variants'), [
            'name' => 'Tandem',
            'duration_minutes' => 60,
            'price_override_cents' => 8_000,
            'resources' => [[
                'resource_type' => 'pool',
                'resource_category_id' => $category->id,
                'resource_id' => $tandem->id,
                'quantity' => 1,
                'capacity' => 2,
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('Tandem', $created['name']);
        $this->assertSame(8_000, $created['price_override_cents']);
        $this->assertSame(2, $created['resources'][0]['capacity']);
        $this->assertSame($workspace['tenant']->id, ServiceVariant::withoutGlobalScope(TenantScope::class)->findOrFail($created['id'])->tenant_id);

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/variants/'.$created['id']), [
            'name' => 'Tandem kayak',
            'price_override_cents' => 9_000,
        ])->assertOk()->assertJsonPath('data.name', 'Tandem kayak')->assertJsonPath('data.price_override_cents', 9_000);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/variants'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $created['id']);

        $staff = $this->workspace('staff.test', 'staff');
        $this->actingAsWorkspace($staff);
        $foreign = Service::withoutGlobalScope(TenantScope::class)->findOrFail($service->id);
        app(TenantManager::class)->set($staff['tenant']);
        $staffService = Service::query()->create([
            'tenant_id' => $staff['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $staff['tenant']->id])->id,
            'name' => 'Staff paddle',
            'kind' => 'rental',
        ]);
        app(TenantManager::class)->forget();

        $this->getJson($this->tenantUrl('staff.test', '/api/v1/services/'.$staffService->id.'/variants'))->assertOk();
        $this->postJson($this->tenantUrl('staff.test', '/api/v1/services/'.$staffService->id.'/variants'), [
            'name' => 'Two hours',
            'duration_minutes' => 120,
        ])->assertForbidden();
        $this->getJson($this->tenantUrl('staff.test', '/api/v1/services/'.$foreign->id.'/variants/'.$created['id']))->assertNotFound();
    }

    public function test_a_variant_from_another_workspace_is_not_found_and_cannot_use_its_resources(): void
    {
        [$alpha, $service, $tandem, $category] = $this->shop();
        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($alpha);

        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/variants'), [
            'name' => 'Tandem',
            'duration_minutes' => 60,
            'resources' => [[
                'resource_type' => 'pool',
                'resource_category_id' => $category->id,
                'resource_id' => $tandem->id,
                'quantity' => 1,
                'capacity' => 2,
            ]],
        ])->assertCreated()->json('data');

        $this->actingAsWorkspace($beta);
        $this->getJson($this->tenantUrl('beta.test', '/api/v1/services/'.$service->id.'/variants/'.$created['id']))->assertNotFound();

        app(TenantManager::class)->set($beta['tenant']);
        $betaLocation = Location::factory()->create(['tenant_id' => $beta['tenant']->id, 'timezone' => 'America/New_York']);
        $betaService = Service::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaLocation->id,
            'name' => 'Other',
            'kind' => 'rental',
        ]);
        $betaCategory = ResourceCategory::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta kayaks',
            'slug' => 'beta-kayaks',
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('beta.test', '/api/v1/services/'.$betaService->id.'/variants'), [
            'name' => 'Stolen',
            'duration_minutes' => 60,
            'resources' => [[
                'resource_type' => 'pool',
                'resource_category_id' => $betaCategory->id,
                'resource_id' => $tandem->id,
                'quantity' => 1,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['resources.0.resource_id']);

        $this->assertSame(1, ServiceVariant::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_an_asset_mapping_must_match_the_asset_type_and_capacity(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $category = ResourceCategory::factory()->create(['tenant_id' => $workspace['tenant']->id, 'name' => 'Singles', 'slug' => 'singles']);
        $other = ResourceCategory::factory()->create(['tenant_id' => $workspace['tenant']->id, 'name' => 'Tandems', 'slug' => 'tandems']);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'resource_category_id' => $category->id,
            'capacity' => 1,
            'name' => 'Single 1',
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/variants'), [
            'name' => 'Wrong type',
            'duration_minutes' => 60,
            'resources' => [[
                'resource_type' => 'asset',
                'resource_category_id' => $other->id,
                'resource_id' => $asset->id,
                'quantity' => 1,
                'capacity' => 1,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['resources.0.resource_category_id']);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/variants'), [
            'name' => 'Wrong capacity',
            'duration_minutes' => 60,
            'resources' => [[
                'resource_type' => 'asset',
                'resource_category_id' => $category->id,
                'resource_id' => $asset->id,
                'quantity' => 1,
                'capacity' => 2,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['resources.0.capacity']);

        $this->assertSame(0, ServiceVariant::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_availability_holds_and_party_size_use_the_variant_and_ignore_it_when_omitted(): void
    {
        [$workspace, $service, $tandem, $category, $single] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $variantId = $this->tandemVariant($service, $tandem, $category);

        $plain = $this->hold($service, null, 'plain', '2026-06-15T16:00:00Z', '2026-06-15T17:00:00Z', 1);
        $this->assertSame($single->id, $this->heldPool($workspace['tenant']->id, $plain));

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T16:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
        ])))->assertOk()->assertJsonPath('data.available', false)->assertJsonPath('data.variant_id', null);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
            'service_id' => $service->id,
            'variant_id' => $variantId,
            'starts_at' => '2026-06-15T16:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
        ])))->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.variant_id', $variantId)
            ->assertJsonPath('data.offered_durations.0.duration_minutes', 60);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
            'service_id' => $service->id,
            'variant_id' => $variantId,
            'starts_at' => '2026-06-15T16:00:00Z',
            'ends_at' => '2026-06-15T18:00:00Z',
        ])))->assertOk()->assertJsonPath('data.duration_ok', false)->assertJsonPath('data.available', false);

        $tandemHold = $this->hold($service, $variantId, 'tandem', '2026-06-15T16:00:00Z', '2026-06-15T17:00:00Z', 2);
        $this->assertSame($tandem->id, $this->heldPool($workspace['tenant']->id, $tandemHold));
        $this->assertSame($variantId, Booking::withoutGlobalScope(TenantScope::class)->findOrFail($tandemHold)->service_variant_id);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'variant_id' => $variantId,
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T19:00:00Z',
            'participants' => 4,
            'idempotency_key' => 'too-many',
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertStatus(409);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$tandemHold.'/party-size'), [
            'participants' => 4,
        ])->assertStatus(409);
        $this->assertSame(2, Booking::withoutGlobalScope(TenantScope::class)->findOrFail($tandemHold)->participants);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'variant_id' => $variantId,
            'starts_at' => '2026-06-15T16:00:00Z',
            'ends_at' => '2026-06-15T18:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'wrong-length',
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertStatus(409);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$tandemHold.'/confirm'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$tandemHold.'/reschedule'), [
            'starts_at' => '2026-06-15T19:00:00Z',
            'ends_at' => '2026-06-15T20:00:00Z',
        ])->assertOk();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($tandem->id, Allocation::query()->where('booking_id', $tandemHold)->value('pool_id'));
        app(TenantManager::class)->forget();

        $this->deleteJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/variants/'.$variantId))
            ->assertStatus(409);
    }

    public function test_a_quote_uses_the_variant_price_and_still_applies_a_peak_rate(): void
    {
        [$workspace, $service, $tandem, $category] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $variantId = $this->tandemVariant($service, $tandem, $category);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Open',
            'kind' => 'base',
            'amount_cents' => 4_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Afternoon peak',
            'kind' => 'base',
            'amount_cents' => 16_000,
            'local_start' => '10:00',
            'local_end' => '14:00',
        ])->assertCreated();

        $plain = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$this->hold($service, null, 'base', '2026-06-15T12:00:00Z', '2026-06-15T13:00:00Z', 1).'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(4_000, $plain['services_cents']);
        $this->assertArrayNotHasKey('variant_id', $plain);

        $override = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$this->hold($service, $variantId, 'off', '2026-06-15T12:00:00Z', '2026-06-15T13:00:00Z', 2).'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(8_000, $override['services_cents']);
        $this->assertSame(8_000, $override['basis_cents']);
        $this->assertSame(240, $override['fee_cents']);
        $this->assertSame($variantId, $override['variant_id']);
        $this->assertSame(8_000, $override['price_override_cents']);

        $crossed = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$this->hold($service, $variantId, 'cross', '2026-06-15T13:30:00Z', '2026-06-15T14:30:00Z', 2).'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(12_000, $crossed['services_cents']);
        $this->assertSame(360, $crossed['fee_cents']);
        $this->assertSame(30, $crossed['time_windows'][0]['minutes']);

        $peak = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$this->hold($service, $variantId, 'peak', '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 2).'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(16_000, $peak['services_cents']);
        $this->assertSame(480, $peak['fee_cents']);
        $this->assertSame(60, $peak['time_windows'][0]['minutes']);
    }

    public function test_a_percent_operator_fee_uses_the_variant_total_including_peak_minutes(): void
    {
        [$workspace, $service, $tandem, $category] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $variantId = $this->tandemVariant($service, $tandem, $category);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Open',
            'kind' => 'base',
            'amount_cents' => 4_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Afternoon peak',
            'kind' => 'base',
            'amount_cents' => 16_000,
            'local_start' => '10:00',
            'local_end' => '14:00',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/operator-fees'), [
            'name' => 'Fuel',
            'pricing' => 'percent',
            'percent_bps' => 1_000,
            'service_id' => $service->id,
        ])->assertCreated();

        $plain = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$this->hold($service, null, 'fee-base', '2026-06-15T12:00:00Z', '2026-06-15T13:00:00Z', 1).'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(4_000, $plain['services_cents']);
        $this->assertSame(400, $plain['mandatory_operator_fees_cents']);
        $this->assertSame(4_400, $plain['basis_cents']);
        $this->assertSame(132, $plain['fee_cents']);

        $override = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$this->hold($service, $variantId, 'fee-off', '2026-06-15T12:00:00Z', '2026-06-15T13:00:00Z', 2).'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(8_000, $override['services_cents']);
        $this->assertSame(800, $override['mandatory_operator_fees_cents']);
        $this->assertSame(8_800, $override['basis_cents']);
        $this->assertSame(264, $override['fee_cents']);

        $peak = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$this->hold($service, $variantId, 'fee-peak', '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 2).'/pricing'))
            ->assertOk()
            ->json('data.payload');
        $this->assertSame(16_000, $peak['services_cents']);
        $this->assertSame(1_600, $peak['mandatory_operator_fees_cents']);
        $this->assertSame(17_600, $peak['basis_cents']);
        $this->assertSame(528, $peak['fee_cents']);
    }

    public function test_a_service_without_variants_still_books_its_own_length_and_price(): void
    {
        [$workspace, $service] = $this->shop();
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Open',
            'kind' => 'base',
            'amount_cents' => 4_000,
        ])->assertCreated();

        $bookingId = $this->hold($service, null, 'today', '2026-06-15T12:00:00Z', '2026-06-15T13:00:00Z', 1);
        $payload = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->json('data.payload');

        $this->assertSame(4_000, $payload['services_cents']);
        $this->assertSame(120, $payload['fee_cents']);
        $this->assertNull(Booking::withoutGlobalScope(TenantScope::class)->findOrFail($bookingId)->service_variant_id);
        $this->assertSame(0, ServiceVariant::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(1, PriceRule::withoutGlobalScope(TenantScope::class)->count());
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Pool, 3: ResourceCategory, 4: Pool}
     */
    private function shop(string $host = 'dock.test'): array
    {
        $workspace = $this->workspace($host);
        app(TenantManager::class)->set($workspace['tenant']);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
            'name' => 'Dock',
        ]);
        $singleCategory = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Single kayaks',
            'slug' => 'single-kayaks',
        ]);
        $tandemCategory = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Tandem kayaks',
            'slug' => 'tandem-kayaks',
        ]);
        $single = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $singleCategory->id,
            'name' => 'Single kayaks',
            'quantity' => 1,
        ]);
        $tandem = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $tandemCategory->id,
            'name' => 'Tandem kayaks',
            'quantity' => 1,
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'pool',
            'resource_id' => $single->id,
            'quantity' => 1,
        ]);
        ServiceDuration::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => '1 hour',
            'quantity' => 1,
            'unit' => 'hours',
            'duration_minutes' => 60,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service, $tandem, $tandemCategory, $single];
    }

    private function tandemVariant(Service $service, Pool $tandem, ResourceCategory $category): string
    {
        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/variants'), [
            'name' => 'Tandem',
            'duration_minutes' => 60,
            'price_override_cents' => 8_000,
            'resources' => [[
                'resource_type' => 'pool',
                'resource_category_id' => $category->id,
                'resource_id' => $tandem->id,
                'quantity' => 1,
                'capacity' => 2,
            ]],
        ])->assertCreated()->json('data.id');
    }

    private function hold(Service $service, ?string $variantId, string $key, string $starts, string $ends, int $participants): string
    {
        $body = [
            'service_id' => $service->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => $participants,
            'idempotency_key' => $key,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ];

        if ($variantId !== null) {
            $body['variant_id'] = $variantId;
        }

        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $body)
            ->assertCreated()
            ->json('data.id');
    }

    private function heldPool(string $tenantId, string $bookingId): ?string
    {
        return Hold::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('booking_id', $bookingId)
            ->value('pool_id');
    }
}
