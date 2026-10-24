<?php

namespace Tests\Feature\Allocation;

use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PartySizeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_confirmed_party_grows_and_shrinks_with_the_pool(): void
    {
        [$workspace, $service, $pool] = $this->kayaks(quantity: 6);
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 1, 'grow');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        $grown = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 3,
        ])->assertOk()
            ->assertJsonPath('data.participants', 3)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.named_participant_count', 0);

        $this->assertSame([], $grown->json('data.named_participants'));

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(3, (int) Allocation::query()->where('booking_id', $bookingId)->where('pool_id', $pool->id)->value('quantity'));
        $this->assertSame(3, (int) Booking::query()->findOrFail($bookingId)->participants);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 3,
        ])->assertOk()->assertJsonPath('data.participants', 3);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 1,
        ])->assertOk()->assertJsonPath('data.participants', 1);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, (int) Allocation::query()->where('booking_id', $bookingId)->value('quantity'));
        $this->assertSame(3, AuditLog::query()->where('action', 'booking.party_size_changed')->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_held_party_updates_the_hold_and_a_short_party_stays_flagged(): void
    {
        [$workspace, $service] = $this->kayaks(quantity: 6);
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $service->min_participants = 4;
        $service->save();
        app(TenantManager::class)->forget();

        $bookingId = $this->hold($service, 2, 'held-size');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 3,
        ])->assertOk()
            ->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.participants', 3)
            ->assertJsonPath('data.below_minimum', true);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Allocation::query()->where('booking_id', $bookingId)->count());
        $this->assertSame(3, (int) Hold::query()->where('booking_id', $bookingId)->value('quantity'));
        app(TenantManager::class)->forget();
    }

    public function test_a_conflict_keeps_the_original_party_and_offers_a_smaller_one(): void
    {
        [$workspace, $service, $pool, $asset, $other] = $this->kayaks(quantity: 4, withBoats: true);
        $this->actingAsWorkspace($workspace);
        $first = $this->hold($service, 1, 'first');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/confirm'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $poolOnly = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Kayak only',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $poolOnly->id,
            'resource_type' => 'pool',
            'resource_id' => $pool->id,
            'quantity' => 1,
            'per_participants' => 1,
        ]);
        app(TenantManager::class)->forget();

        $second = $this->hold($poolOnly, 1, 'second');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/confirm'))->assertOk();

        $refused = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/party-size'), [
            'participants' => 4,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That time is no longer available.')
            ->assertJsonPath('offers.authoritative', false)
            ->assertJsonPath('offers.smaller_participants', 3);

        $this->assertContains($other->id, $refused->json('offers.other_asset_ids'));

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Booking::query()->findOrFail($first)->participants);
        $this->assertSame(1, (int) Allocation::query()->where('booking_id', $first)->where('pool_id', $pool->id)->value('quantity'));
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $first)->whereNotNull('asset_id')->value('asset_id'));
        $this->assertSame(2, (int) Allocation::query()->where('pool_id', $pool->id)->sum('quantity'));
        app(TenantManager::class)->forget();
    }

    public function test_an_asset_that_cannot_cover_the_party_offers_the_size_that_fits(): void
    {
        [$workspace, $service] = $this->kayaks(quantity: 4);
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        ResourceRequirement::query()->where('service_id', $service->id)->delete();
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Tandem',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
            'per_participants' => 2,
        ]);
        app(TenantManager::class)->forget();

        $bookingId = $this->hold($service, 1, 'tandem');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 3,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'An individual asset can only be held once.')
            ->assertJsonPath('offers.smaller_participants', 2);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Booking::query()->findOrFail($bookingId)->participants);
        app(TenantManager::class)->forget();
    }

    public function test_party_size_cannot_pass_the_maximum_or_drop_below_named_people(): void
    {
        [$workspace, $service] = $this->kayaks(quantity: 8);
        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $service->max_participants = 4;
        $service->save();
        app(TenantManager::class)->forget();

        $bookingId = $this->hold($service, 3, 'named');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 5,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That party is larger than this service allows.')
            ->assertJsonPath('offers', []);

        $ada = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'Ada Lovelace',
            'is_minor' => false,
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Ada Lovelace');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'Grace Hopper',
            'is_minor' => false,
        ])->assertCreated();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.named_participant_count', 2)
            ->assertJsonPath('data.named_participants.0.name', 'Ada Lovelace')
            ->assertJsonPath('data.named_participants.1.name', 'Grace Hopper');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => '   ',
            'is_minor' => false,
        ])->assertStatus(422);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 1,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Party size cannot drop below the named participants already added.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(3, Booking::query()->findOrFail($bookingId)->participants);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 2,
        ])->assertOk()->assertJsonPath('data.participants', 2);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'Katherine Johnson',
            'is_minor' => false,
        ])->assertStatus(422)->assertJsonValidationErrors('name');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/cancel'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 2,
        ])->assertStatus(409)->assertJsonPath('message', 'Only a held or confirmed booking can change party size.');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'Mary Jackson',
            'is_minor' => false,
        ])->assertStatus(409)->assertJsonPath('message', 'Only a held or confirmed booking can name a participant.');

        $shown = $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))->assertOk();
        $this->assertSame($ada->json('data.id'), $shown->json('data.named_participants.0.id'));
        $this->assertSame(2, $shown->json('data.named_participant_count'));
    }

    public function test_a_departure_seat_refuses_a_party_the_departure_cannot_hold(): void
    {
        [$workspace, $service] = $this->tour();
        $this->actingAsWorkspace($workspace);
        $departure = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'seats' => 6,
        ])->assertCreated()->json('data');

        $first = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $departure['id'],
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'dep-a',
        ])->assertCreated()->json('data.id');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/confirm'))->assertOk();

        $second = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $departure['id'],
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'dep-b',
        ])->assertCreated()->json('data.id');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/party-size'), [
            'participants' => 5,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That departure is full.')
            ->assertJsonPath('offers.smaller_participants', 4);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/party-size'), [
            'participants' => 4,
        ])->assertOk()->assertJsonPath('data.participants', 4);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Allocation::query()->where('booking_id', $second)->count());
        $this->assertSame(4, (int) Hold::query()->where('booking_id', $second)->value('quantity'));
        $this->assertSame(6, Departure::query()->findOrFail($departure['id'])->headcount());
        app(TenantManager::class)->forget();
    }

    public function test_another_workspace_cannot_change_the_party(): void
    {
        [$workspace, $service] = $this->kayaks(quantity: 4);
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, 2, 'isolated');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$bookingId.'/party-size'), [
            'participants' => 1,
        ])->assertNotFound();
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$bookingId.'/participants'), [
            'name' => 'Ada Lovelace',
        ])->assertNotFound();

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.participants', 2)
            ->assertJsonPath('data.named_participant_count', 0);
    }

    private function hold(Service $service, int $participants, string $key): string
    {
        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => $participants,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Pool, 3?: Asset, 4?: Asset}
     */
    private function kayaks(int $quantity, bool $withBoats = false): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayaks',
            'quantity' => $quantity,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
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
            'resource_id' => $pool->id,
            'quantity' => 1,
            'per_participants' => 1,
        ]);

        if (! $withBoats) {
            app(TenantManager::class)->forget();

            return [$workspace, $service, $pool];
        }

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
        $other = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat B',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service, $pool, $asset, $other];
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
