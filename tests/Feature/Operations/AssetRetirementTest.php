<?php

namespace Tests\Feature\Operations;

use App\Enums\ResourceStatus;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class AssetRetirementTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_retiring_an_asset_flags_future_bookings_and_keeps_history(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $asset] = $this->rental();
        $this->actingAsWorkspace($workspace);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/maintenance-records'), [
            'inspected_at' => '2026-06-01T12:00:00Z',
            'summary' => 'Hull was sound.',
        ])->assertCreated();

        $past = $this->confirmed($service, '2026-06-16T13:00:00Z', '2026-06-16T14:00:00Z', 'past-boat');
        $future = $this->confirmed($service, '2026-06-17T15:00:00Z', '2026-06-17T16:00:00Z', 'future-boat');
        $held = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-18T15:00:00Z',
            'ends_at' => '2026-06-18T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'held-boat',
        ])->assertCreated()->json('data.id');

        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id), [
            'status' => 'retired',
        ])->assertUnprocessable();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/retire'))
            ->assertOk()
            ->assertJsonPath('data.status', 'retired')
            ->assertJsonPath('data.replacement_asset_id', null)
            ->assertJsonPath('flagged_bookings', 1)
            ->assertJsonPath('reassignment_offers', []);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/retire'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$future))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.attention_reason', 'Asset is retired.');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$past))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.attention_reason', null);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$held))
            ->assertOk()
            ->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.attention_reason', null);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/maintenance-records'))
            ->assertOk()
            ->assertJsonPath('data.0.summary', 'Hull was sound.');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/assets'))
            ->assertOk()
            ->assertJsonFragment(['id' => $asset->id, 'status' => 'retired']);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, Allocation::query()->count());
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $past)->value('asset_id'));
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $future)->value('asset_id'));
        $this->assertSame('confirmed', Booking::query()->findOrFail($future)->status->value);
        $this->assertTrue(AuditLog::query()->where('action', 'asset.retired')->where('subject_id', $asset->id)->exists());
        app(TenantManager::class)->forget();
    }

    public function test_a_replacement_is_offered_and_accepted_by_the_existing_reassignment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $asset, $replacement, $location] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $free = $this->confirmed($service, '2026-06-18T15:00:00Z', '2026-06-18T16:00:00Z', 'free-slot');
        $blocked = $this->confirmed($service, '2026-06-17T15:00:00Z', '2026-06-17T16:00:00Z', 'blocked-slot');

        app(TenantManager::class)->set($workspace['tenant']);
        $other = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Replacement boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $other->id,
            'resource_type' => 'asset',
            'resource_id' => $replacement->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();
        $this->confirmed($other, '2026-06-17T15:00:00Z', '2026-06-17T16:00:00Z', 'occupies-replacement');

        $retired = $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/retire'), [
            'replacement_asset_id' => $replacement->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'retired')
            ->assertJsonPath('data.replacement_asset_id', $replacement->id)
            ->assertJsonPath('flagged_bookings', 2);

        $offers = $retired->json('reassignment_offers');
        $this->assertCount(2, $offers);
        $this->assertEqualsCanonicalizing(
            [$free, $blocked],
            array_column($offers, 'booking_id'),
        );
        $this->assertSame([$asset->id], array_values(array_unique(array_column($offers, 'from_asset_id'))));
        $this->assertSame([$replacement->id], array_values(array_unique(array_column($offers, 'to_asset_id'))));

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $free)->value('asset_id'));
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $blocked)->value('asset_id'));
        app(TenantManager::class)->forget();

        $blockedOffer = collect($offers)->firstWhere('booking_id', $blocked);
        $freeOffer = collect($offers)->firstWhere('booking_id', $free);
        $this->assertIsArray($blockedOffer);
        $this->assertIsArray($freeOffer);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$blocked.'/reassign'), [
            'from_asset_id' => $blockedOffer['from_asset_id'],
            'to_asset_id' => $blockedOffer['to_asset_id'],
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $blocked)->value('asset_id'));
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$free.'/reassign'), [
            'from_asset_id' => $freeOffer['from_asset_id'],
            'to_asset_id' => $freeOffer['to_asset_id'],
        ])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($replacement->id, Allocation::query()->where('booking_id', $free)->value('asset_id'));
        $this->assertSame('confirmed', Booking::query()->findOrFail($free)->status->value);
        app(TenantManager::class)->forget();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
            'service_id' => $service->id,
            'starts_at' => '2026-06-19T15:00:00Z',
            'ends_at' => '2026-06-19T16:00:00Z',
        ])))->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.available', false);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-19T15:00:00Z',
            'ends_at' => '2026-06-19T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'after-retire',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'A required resource is not available.');

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id), [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.replacement_asset_id', null);

        app(TenantManager::class)->set($workspace['tenant']);
        $restored = Asset::query()->findOrFail($asset->id);
        $this->assertSame(ResourceStatus::Active, $restored->status);
        $this->assertNull($restored->replacement_asset_id);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $blocked)->value('asset_id'));
        app(TenantManager::class)->forget();
    }

    public function test_a_replacement_must_be_another_active_asset_in_the_same_category_and_location(): void
    {
        [$workspace, $service, $asset, $replacement, $location, $category] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $booking = $this->confirmed($service, '2026-06-17T15:00:00Z', '2026-06-17T16:00:00Z', 'stay-put');

        app(TenantManager::class)->set($workspace['tenant']);
        $elsewhere = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $otherCategory = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Kayaks',
            'slug' => 'kayaks',
        ]);
        $otherPlace = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $elsewhere->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat C',
        ]);
        $otherKind = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $otherCategory->id,
            'name' => 'Kayak',
        ]);
        $blockedAsset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat D',
            'status' => ResourceStatus::Blocked,
        ]);
        app(TenantManager::class)->forget();

        foreach ([$asset->id, $otherPlace->id, $otherKind->id, $blockedAsset->id] as $candidate) {
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/retire'), [
                'replacement_asset_id' => $candidate,
            ])->assertUnprocessable()->assertJsonValidationErrors('replacement_asset_id');
        }

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/retire'), [
            'replacement_asset_id' => $replacement->id,
        ])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(ResourceStatus::Retired, Asset::query()->findOrFail($asset->id)->status);
        $this->assertSame('confirmed', Booking::query()->findOrFail($booking)->status->value);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $booking)->value('asset_id'));
        app(TenantManager::class)->forget();
    }

    public function test_retirement_does_not_cross_workspaces(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $betaAsset = Asset::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $beta['tenant']->id])->id,
            'name' => 'Beta boat',
        ]);
        $alphaLocation = Location::factory()->create(['tenant_id' => $alpha['tenant']->id]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Boats',
            'slug' => 'boats',
        ]);
        $alphaAsset = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $alphaLocation->id,
            'resource_category_id' => $category->id,
            'name' => 'Alpha boat',
        ]);
        $this->actingAsWorkspace($alpha);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/assets/'.$betaAsset->id.'/retire'))
            ->assertNotFound();

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/assets/'.$alphaAsset->id.'/retire'), [
            'replacement_asset_id' => $betaAsset->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('replacement_asset_id');

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(ResourceStatus::Active, Asset::query()->findOrFail($alphaAsset->id)->status);
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(ResourceStatus::Active, Asset::query()->findOrFail($betaAsset->id)->status);
        app(TenantManager::class)->forget();
    }

    public function test_staff_cannot_retire_an_asset_and_a_pool_cannot_be_retired(): void
    {
        $workspace = $this->workspace('dock.test', 'staff');
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $workspace['tenant']->id])->id,
            'name' => 'Staff boat',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $asset->location_id,
            'name' => 'Kayaks',
            'quantity' => 4,
        ]);
        $this->actingAsWorkspace($workspace);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset->id.'/retire'))
            ->assertForbidden();

        $owner = $this->workspace('harbor.test');
        $this->actingAsWorkspace($owner);
        $ownerPool = Pool::factory()->create([
            'tenant_id' => $owner['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $owner['tenant']->id])->id,
            'name' => 'Paddleboards',
            'quantity' => 3,
        ]);

        $this->patchJson($this->tenantUrl('harbor.test', '/api/v1/pools/'.$ownerPool->id), [
            'status' => 'retired',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        app(TenantManager::class)->set($owner['tenant']);

        try {
            $ownerPool->status = ResourceStatus::Retired;
            $ownerPool->save();
            $this->fail('A pool was retired.');
        } catch (ValidationException $exception) {
            $this->assertSame('A pool cannot be retired.', $exception->errors()['status'][0]);
        }

        $this->assertSame(ResourceStatus::Active, $ownerPool->refresh()->status);
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(ResourceStatus::Active, Asset::query()->findOrFail($asset->id)->status);
        $this->assertSame(ResourceStatus::Active, $pool->refresh()->status);
        app(TenantManager::class)->forget();
    }

    private function confirmed(Service $service, string $start, string $end, string $key): string
    {
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset}
     */
    private function rental(): array
    {
        [$workspace, $service, $asset] = $this->pair();

        return [$workspace, $service, $asset];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset, 3: Asset, 4: Location, 5: ResourceCategory}
     */
    private function pair(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Boats',
            'slug' => 'boats',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat A',
        ]);
        $replacement = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat B',
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

        return [$workspace, $service, $asset, $replacement, $location, $category];
    }
}
