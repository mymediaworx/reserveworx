<?php

namespace Tests\Feature\Allocation;

use App\Enums\BookingStatus;
use App\Enums\WaitlistStatus;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Pool;
use App\Models\RentalCheckout;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ProductDecisionTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_cancel_promotes_the_oldest_entry_that_fits_and_queues_a_hook(): void
    {
        [$workspace, $service] = $this->rental(maxParticipants: 4);
        $this->actingAsWorkspace($workspace);

        $held = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'held-1'))
            ->assertCreated()
            ->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'too-big', 8, true))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'waiting');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'next', 1, true))
            ->assertStatus(202);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$held['id'].'/cancel'))
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        app(TenantManager::class)->set($workspace['tenant']);
        $entries = WaitlistEntry::query()->orderBy('id')->get();
        $this->assertSame(WaitlistStatus::Waiting, $entries[0]->status);
        $this->assertSame(WaitlistStatus::Promoted, $entries[1]->status);
        $promoted = Booking::query()->findOrFail($entries[1]->booking_id);
        $this->assertSame(BookingStatus::Held, $promoted->status);
        $this->assertTrue(Hold::query()->where('booking_id', $promoted->id)->where('expires_at', '>', now())->exists());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'waitlist.promoted')->count());
        $this->assertSame($promoted->id, NotificationHook::query()->firstOrFail()->booking_id);
        app(TenantManager::class)->forget();
    }

    public function test_no_show_and_expired_hold_purge_promote_the_waiting_party(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);

        $confirmed = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'show-1'))
            ->assertCreated()
            ->json('data');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$confirmed['id'].'/confirm'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'show-wait', 1, true))
            ->assertStatus(202);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$confirmed['id'].'/no-show'))
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(WaitlistStatus::Promoted, WaitlistEntry::query()->firstOrFail()->status);
        $this->assertSame(1, NotificationHook::query()->count());
        app(TenantManager::class)->forget();

        $expired = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-16T15:00:00Z', '2026-06-16T16:00:00Z', 'expire-1'))
            ->assertCreated()
            ->json('data');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-16T15:00:00Z', '2026-06-16T16:00:00Z', 'expire-wait', 1, true))
            ->assertStatus(202);

        Hold::withoutGlobalScope(TenantScope::class)
            ->where('booking_id', $expired['id'])
            ->update(['expires_at' => now()->subMinute()]);

        $this->artisan('holds:purge-expired')->assertSuccessful();

        app(TenantManager::class)->set($workspace['tenant']);
        $waiting = WaitlistEntry::query()->where('idempotency_key', 'expire-wait')->firstOrFail();
        $this->assertSame(WaitlistStatus::Promoted, $waiting->status);
        $this->assertTrue(Hold::query()->where('booking_id', $waiting->booking_id)->where('expires_at', '>', now())->exists());
        $this->assertFalse(Hold::query()->where('booking_id', $expired['id'])->exists());
        app(TenantManager::class)->forget();
    }

    public function test_a_guide_user_and_a_guide_asset_each_block_an_overlap(): void
    {
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $guideAsset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Guide',
            'is_guide' => true,
        ]);
        $kayak = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak',
        ]);
        $other = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $other->id,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $tour = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Guided paddle',
            'kind' => 'tour',
            'waitlist_enabled' => false,
        ]);
        $rental = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Solo kayak',
            'kind' => 'rental',
            'waitlist_enabled' => false,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $tour->id,
            'resource_type' => 'asset',
            'resource_id' => $guideAsset->id,
            'quantity' => 1,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $rental->id,
            'resource_type' => 'asset',
            'resource_id' => $kayak->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'guide-1',
            'guide_user_id' => $workspace['user']->id,
        ])->assertCreated()->assertJsonPath('data.guide_user_id', $workspace['user']->id);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $rental->id,
            'starts_at' => '2026-06-15T15:30:00Z',
            'ends_at' => '2026-06-15T16:30:00Z',
            'participants' => 1,
            'idempotency_key' => 'guide-user-clash',
            'guide_user_id' => $workspace['user']->id,
        ])->assertStatus(409)->assertJsonPath('message', 'That guide is already booked.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'guide-asset-clash',
            'guide_user_id' => $other->id,
        ])->assertStatus(409)->assertJsonPath('message', 'That guide is already booked.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $rental->id,
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T19:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'guide-later',
            'guide_user_id' => $workspace['user']->id,
        ])->assertCreated();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T19:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'not-a-member',
            'guide_user_id' => User::factory()->create()->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('guide_user_id');
    }

    public function test_named_durations_are_minutes_or_hours_and_departures_keep_their_own_time(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/durations'), [
            'name' => 'Half day',
            'quantity' => 4,
            'unit' => 'hours',
        ])->assertCreated()
            ->assertJsonPath('data.duration_minutes', 240)
            ->assertJsonPath('data.unit', 'hours');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/durations'), [
            'name' => '90 minutes',
            'quantity' => 90,
            'unit' => 'minutes',
        ])->assertCreated()->assertJsonPath('data.duration_minutes', 90);

        $listed = $this->getJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id))
            ->assertOk()
            ->json('data.durations');
        $this->assertEqualsCanonicalizing(['Half day', '90 minutes'], array_column($listed, 'name'));

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T19:00:00Z', 'half-day'))
            ->assertCreated();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-16T15:00:00Z', '2026-06-16T15:30:00Z', 'too-short'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Choose a rental length offered for this service.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-16T15:00:00Z', '2026-06-16T16:30:00Z', 'ninety'))
            ->assertCreated();

        $hint = $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
            'service_id' => $service->id,
            'starts_at' => '2026-06-17T15:00:00Z',
            'ends_at' => '2026-06-17T15:30:00Z',
        ])))->assertOk()->json('data');
        $this->assertFalse($hint['authoritative']);
        $this->assertFalse($hint['duration_ok']);
        $this->assertTrue($hint['capacity_checked']);

        app(TenantManager::class)->set($workspace['tenant']);
        $tour = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Harbor tour',
            'kind' => 'tour',
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $tour->id,
            'resource_type' => 'asset',
            'resource_id' => Asset::query()->where('location_id', $service->location_id)->firstOrFail()->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$tour->id.'/durations'), [
            'name' => '90 minutes',
            'quantity' => 90,
            'unit' => 'minutes',
        ])->assertCreated();

        $departure = $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $tour->id,
            'starts_at' => '2026-06-18T15:00:00Z',
            'ends_at' => '2026-06-18T17:00:00Z',
            'seats' => 4,
        ])->assertCreated()->json('data');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $tour->id,
            'departure_id' => $departure['id'],
            'starts_at' => '2026-06-18T15:00:00Z',
            'ends_at' => '2026-06-18T17:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'tour-length',
        ])->assertCreated();
    }

    public function test_turnaround_and_an_overdue_checkout_extend_the_block(): void
    {
        [$workspace, $service] = $this->rental(turnaround: 30);
        $this->actingAsWorkspace($workspace);
        Carbon::setTestNow(CarbonImmutable::parse('2026-06-15T14:50:00Z'));

        try {
            $booking = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'out-1'))
                ->assertCreated()
                ->json('data');
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking['id'].'/confirm'))->assertOk();
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking['id'].'/checkout'))->assertOk();
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking['id'].'/checkout'))->assertOk();

            app(TenantManager::class)->set($workspace['tenant']);
            $this->assertSame(1, RentalCheckout::query()->where('booking_id', $booking['id'])->count());
            app(TenantManager::class)->forget();

            Carbon::setTestNow(CarbonImmutable::parse('2026-06-15T16:40:00Z'));
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T16:30:00Z', '2026-06-15T17:30:00Z', 'during-overdue'))
                ->assertStatus(409);

            $blocked = $this->getJson($this->tenantUrl('dock.test', '/api/v1/availability?'.http_build_query([
                'service_id' => $service->id,
                'starts_at' => '2026-06-15T16:30:00Z',
                'ends_at' => '2026-06-15T17:30:00Z',
            ])))->assertOk()->json('data');
            $this->assertFalse($blocked['authoritative']);
            $this->assertTrue($blocked['capacity_checked']);
            $this->assertFalse($blocked['available']);

            Carbon::setTestNow(CarbonImmutable::parse('2026-06-15T15:20:00Z'));
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T16:00:00Z', '2026-06-15T17:00:00Z', 'after-return', 1, true))
                ->assertStatus(202);

            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking['id'].'/return'))->assertOk();

            app(TenantManager::class)->set($workspace['tenant']);
            $entry = WaitlistEntry::query()->where('idempotency_key', 'after-return')->firstOrFail();
            $this->assertSame(WaitlistStatus::Promoted, $entry->status);
            $this->assertSame(
                '2026-06-15 15:50:00',
                Allocation::query()->where('booking_id', $booking['id'])->firstOrFail()->ends_at?->utc()->format('Y-m-d H:i:s'),
            );
            $this->assertNotNull(RentalCheckout::query()->where('booking_id', $booking['id'])->firstOrFail()->returned_at);
            app(TenantManager::class)->forget();

            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T17:30:00Z', '2026-06-15T18:30:00Z', 'after-block'))
                ->assertCreated();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_an_active_pool_checkout_does_not_consume_the_unit_twice(): void
    {
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'quantity' => 2,
            'turnaround_minutes' => 20,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak pool',
            'kind' => 'rental',
            'waitlist_enabled' => false,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'pool',
            'resource_id' => $pool->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        Carbon::setTestNow(CarbonImmutable::parse('2026-06-15T15:00:00Z'));

        try {
            $first = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'pool-1'))
                ->assertCreated()
                ->json('data');
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first['id'].'/confirm'))->assertOk();
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first['id'].'/checkout'))->assertOk();
            $second = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'pool-2'))
                ->assertCreated()
                ->json('data');
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second['id'].'/confirm'))->assertOk();
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second['id'].'/checkout'))->assertOk();

            Carbon::setTestNow(CarbonImmutable::parse('2026-06-15T16:30:00Z'));
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T16:25:00Z', '2026-06-15T17:25:00Z', 'pool-late'))
                ->assertStatus(409);
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $this->booking($service, '2026-06-15T17:00:00Z', '2026-06-15T18:00:00Z', 'pool-after'))
                ->assertCreated();
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(int $turnaround = 0, ?int $maxParticipants = null): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak',
            'turnaround_minutes' => $turnaround,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Single kayak',
            'kind' => 'rental',
            'max_participants' => $maxParticipants,
            'waitlist_enabled' => true,
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
     * @return array<string, mixed>
     */
    private function booking(Service $service, string $start, string $end, string $key, int $participants = 1, bool $wait = false): array
    {
        return [
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => $participants,
            'idempotency_key' => $key,
            'join_waitlist' => $wait,
        ];
    }
}
