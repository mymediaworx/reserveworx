<?php

namespace Tests\Feature\Allocation;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class HoldOfferTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private string $otherTenantId = '';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_refused_hold_offers_nearby_times_and_same_category_assets(): void
    {
        [$workspace, $service, $free, $taken, $otherCategory, $otherLocation, $blocked, $foreign] = $this->fleet();
        $this->actingAsWorkspace($workspace);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'held',
        ])->assertCreated();

        app(TenantManager::class)->set($workspace['tenant']);
        $otherService = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Second boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $otherService->id,
            'resource_type' => 'asset',
            'resource_id' => $taken->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $otherService->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'taken-boat',
        ])->assertCreated();

        $refused = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'refused',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That time is no longer available.')
            ->assertJsonPath('offers.authoritative', false)
            ->assertJsonMissingPath('data');

        $starts = array_column($refused->json('offers.times'), 'starts_at');
        $this->assertContains('2026-06-15T16:00:00Z', $starts);
        $this->assertContains('2026-06-16T15:00:00Z', $starts);
        $this->assertContains('2026-06-17T15:00:00Z', $starts);
        $this->assertNotContains('2026-06-15T15:00:00Z', $starts);
        $this->assertNotContains('2026-06-15T15:30:00Z', $starts);
        $this->assertNotContains('2026-06-15T20:00:00Z', $starts);
        $this->assertNotContains('2026-06-18T15:00:00Z', $starts);

        $ends = [];

        foreach ($refused->json('offers.times') as $time) {
            $ends[$time['starts_at']] = $time['ends_at'];
        }

        $this->assertSame('2026-06-15T17:00:00Z', $ends['2026-06-15T16:00:00Z']);
        $this->assertSame('2026-06-16T16:00:00Z', $ends['2026-06-16T15:00:00Z']);
        $this->assertSame([$free->id], $refused->json('offers.other_asset_ids'));

        $body = (string) $refused->getContent();
        $this->assertStringNotContainsString($taken->id, $body);
        $this->assertStringNotContainsString($otherCategory->id, $body);
        $this->assertStringNotContainsString($otherLocation->id, $body);
        $this->assertStringNotContainsString($blocked->id, $body);
        $this->assertStringNotContainsString($foreign->id, $body);
        $this->assertSame(
            $this->otherTenantId,
            Asset::withoutGlobalScope(TenantScope::class)->findOrFail($foreign->id)->tenant_id,
        );

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, Booking::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_party_that_is_too_large_stays_a_message_only(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $service->max_participants = 2;
        $service->save();
        app(TenantManager::class)->forget();

        $refused = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 3,
            'idempotency_key' => 'too-big',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That party is larger than this service allows.');

        $this->assertArrayNotHasKey('offers', $refused->json());

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Booking::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_full_departure_offers_another_departure_and_not_a_rental_grid(): void
    {
        [$workspace, $service] = $this->tour();
        $this->actingAsWorkspace($workspace);

        $full = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'seats' => 2,
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T20:00:00Z',
            'seats' => 2,
        ])->assertCreated();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T15:00:00Z',
            'ends_at' => '2026-06-16T17:00:00Z',
            'seats' => 2,
        ])->assertCreated();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-18T15:00:00Z',
            'ends_at' => '2026-06-18T17:00:00Z',
            'seats' => 2,
        ])->assertCreated();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $full,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'full-party',
        ])->assertCreated();

        $refused = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $full,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'one-more',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That departure is full.')
            ->assertJsonPath('offers.authoritative', false)
            ->assertJsonPath('offers.other_asset_ids', []);

        $starts = array_column($refused->json('offers.times'), 'starts_at');
        $this->assertContains('2026-06-16T15:00:00Z', $starts);
        $this->assertNotContains('2026-06-15T15:00:00Z', $starts);
        $this->assertNotContains('2026-06-15T18:00:00Z', $starts);
        $this->assertNotContains('2026-06-16T15:30:00Z', $starts);
        $this->assertNotContains('2026-06-18T15:00:00Z', $starts);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Booking::query()->count());
        app(TenantManager::class)->forget();
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset, 3: Asset, 4: Asset, 5: Asset, 6: Asset, 7: Asset}
     */
    private function fleet(): array
    {
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('pier.test');
        $this->otherTenantId = $other['tenant']->id;
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $elsewhere = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
            'name' => 'Other dock',
        ]);
        $boats = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Skiffs',
        ]);
        $kayaks = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Kayaks',
        ]);
        $required = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $boats->id,
            'name' => 'Boat A',
        ]);
        $free = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $boats->id,
            'name' => 'Boat B',
        ]);
        $taken = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $boats->id,
            'name' => 'Boat C',
        ]);
        $otherCategory = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $kayaks->id,
            'name' => 'Kayak',
        ]);
        $otherLocation = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $elsewhere->id,
            'resource_category_id' => $boats->id,
            'name' => 'Boat D',
        ]);
        $blocked = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $boats->id,
            'name' => 'Boat E',
            'status' => 'blocked',
        ]);
        $foreign = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $boats->id,
            'name' => 'Foreign boat',
        ]);
        Asset::withoutGlobalScope(TenantScope::class)->whereKey($foreign->id)->update([
            'tenant_id' => $other['tenant']->id,
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
            'resource_id' => $required->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service, $free, $taken, $otherCategory, $otherLocation, $blocked, $foreign];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
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

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function tour(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Guide',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Harbor tour',
            'kind' => 'tour',
            'max_participants' => 6,
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
}
