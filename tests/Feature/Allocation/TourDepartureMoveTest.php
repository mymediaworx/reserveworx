<?php

namespace Tests\Feature\Allocation;

use App\Enums\DepartureStatus;
use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\Schedule;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingLineItem;
use App\Models\Departure;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TourDepartureMoveTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_confirmed_party_moves_onto_an_empty_departure_without_changing_price(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $to = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z');
        $bookingId = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 2, 'move-empty');
        $this->confirm($bookingId);
        app(TenantManager::class)->set($workspace['tenant']);
        PricingSnapshot::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $bookingId,
            'currency' => 'usd',
            'payload' => ['customer_total_cents' => 4000, 'marker' => 'stay'],
            'rules_version' => 'test',
        ]);
        $before = Allocation::query()->where('booking_id', $bookingId)->sole();
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $to,
        ])->assertOk()
            ->assertJsonPath('data.departure_id', $to)
            ->assertJsonPath('data.starts_at', '2026-06-16T15:00:00Z')
            ->assertJsonPath('data.ends_at', '2026-06-16T17:00:00Z')
            ->assertJsonPath('data.participants', 2);

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($bookingId);
        $this->assertSame($to, $booking->departure_id);
        $moved = Allocation::query()->where('booking_id', $bookingId)->sole();
        $this->assertNotSame($before->id, $moved->id);
        $this->assertSame('2026-06-16 15:00:00', $moved->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-16 17:00:00', $moved->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('stay', PricingSnapshot::query()->where('booking_id', $bookingId)->sole()->payload['marker']);
        $this->assertSame(4000, PricingSnapshot::query()->where('booking_id', $bookingId)->sole()->payload['customer_total_cents']);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.rescheduled')->where('subject_id', $bookingId)->count());
    }

    public function test_the_party_that_stays_keeps_the_boat_at_the_original_time(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $to = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z');
        $holder = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 2, 'holder');
        $this->confirm($holder);
        $guest = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 2, 'guest');
        $this->confirm($guest);
        app(TenantManager::class)->set($workspace['tenant']);
        $keptId = Allocation::query()->where('booking_id', $holder)->sole()->id;
        $quantity = Allocation::query()->findOrFail($keptId)->quantity;
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$holder.'/reschedule'), [
            'departure_id' => $to,
        ])->assertOk()->assertJsonPath('data.departure_id', $to);

        app(TenantManager::class)->set($workspace['tenant']);
        $kept = Allocation::query()->findOrFail($keptId);
        $this->assertSame($guest, $kept->booking_id);
        $this->assertSame($quantity, $kept->quantity);
        $this->assertSame('2026-06-15 15:00:00', $kept->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 17:00:00', $kept->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, BookingLineItem::query()->where('booking_id', $guest)->count());
        $moved = Allocation::query()->where('booking_id', $holder)->sole();
        $this->assertSame('2026-06-16 15:00:00', $moved->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, BookingLineItem::query()->where('booking_id', $holder)->count());
    }

    public function test_an_overlapping_departure_leaves_the_original_booking_in_place(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $overlap = $this->departure($workspace, $service, '2026-06-15T16:00:00Z', '2026-06-15T18:00:00Z');
        $holder = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 2, 'overlap-holder');
        $this->confirm($holder);
        $guest = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'overlap-guest');
        $this->confirm($guest);

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$holder.'/reschedule'), [
            'departure_id' => $overlap,
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($holder);
        $this->assertSame($from, $booking->departure_id);
        $this->assertSame('2026-06-15 15:00:00', $booking->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($holder, Allocation::query()->sole()->booking_id);
    }

    public function test_a_full_private_or_cancelled_departure_is_refused(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $full = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z', ['seats' => 2]);
        $private = $this->departure($workspace, $service, '2026-06-17T15:00:00Z', '2026-06-17T17:00:00Z', ['private' => true]);
        $cancelled = $this->departure($workspace, $service, '2026-06-18T15:00:00Z', '2026-06-18T17:00:00Z');
        $bookingId = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 2, 'refused');
        $this->confirm($bookingId);
        $filled = $this->party($workspace, $service, $full, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z', 2, 'fills-it');
        $this->confirm($filled);
        $reserved = $this->party($workspace, $service, $private, '2026-06-17T15:00:00Z', '2026-06-17T17:00:00Z', 1, 'takes-private');
        $this->confirm($reserved);
        app(TenantManager::class)->set($workspace['tenant']);
        Departure::query()->whereKey($cancelled)->update(['status' => DepartureStatus::Cancelled]);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $full,
        ])->assertStatus(409)->assertJsonPath('message', 'That departure is full.');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $private,
        ])->assertStatus(409)->assertJsonPath('message', 'That private departure already has a booking.');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $cancelled,
        ])->assertStatus(409)->assertJsonPath('message', 'That departure is cancelled.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($from, Booking::query()->findOrFail($bookingId)->departure_id);
    }

    public function test_another_workspaces_departure_is_refused(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $bookingId = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'ours');
        $this->confirm($bookingId);
        $other = $this->workspace('other.test');
        $otherService = $this->serviceFor($other, 'Other tour');
        $foreign = $this->departure($other, $otherService, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z', [], 'other.test');

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $foreign,
        ])->assertStatus(409)->assertJsonPath('message', 'That departure is not available.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($from, Booking::query()->findOrFail($bookingId)->departure_id);
    }

    public function test_the_same_departure_is_unchanged(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $bookingId = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'same');
        $this->confirm($bookingId);
        app(TenantManager::class)->set($workspace['tenant']);
        $allocationId = Allocation::query()->where('booking_id', $bookingId)->sole()->id;
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $from,
        ])->assertOk()->assertJsonPath('data.departure_id', $from)->assertJsonPath('data.starts_at', '2026-06-15T15:00:00Z');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($allocationId, Allocation::query()->where('booking_id', $bookingId)->sole()->id);
    }

    public function test_a_held_tour_and_a_clock_change_are_refused_and_a_rental_still_moves_by_time(): void
    {
        [$workspace, $service, $asset] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $to = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z');
        $held = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'held-tour');
        $confirmed = $this->party($workspace, $service, $to, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z', 1, 'clock-tour');
        $this->confirm($confirmed);

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$held.'/reschedule'), [
            'departure_id' => $to,
        ])->assertStatus(409)->assertJsonPath('message', 'Only a confirmed booking can be rescheduled.');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$confirmed.'/reschedule'), [
            'starts_at' => '2026-06-18T15:00:00Z',
            'ends_at' => '2026-06-18T17:00:00Z',
        ])->assertStatus(409)->assertJsonPath('message', 'Choose another departure to move a tour booking.');

        app(TenantManager::class)->set($workspace['tenant']);
        $rentalAsset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Rental skiff',
        ]);
        $rental = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $rental->id,
            'resource_type' => 'asset',
            'resource_id' => $rentalAsset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $rentalId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $rental->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'rental-move',
        ])->assertCreated()->json('data.id');
        $this->confirm($rentalId);
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$rentalId.'/reschedule'), [
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T19:00:00Z',
        ])->assertOk()->assertJsonPath('data.starts_at', '2026-06-15T18:00:00Z');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($from, Booking::query()->findOrFail($held)->departure_id);
        $this->assertSame($to, Booking::query()->findOrFail($confirmed)->departure_id);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $confirmed)->sole()->asset_id);
    }

    public function test_the_same_idempotency_key_replays_one_move(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $first = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z');
        $second = $this->departure($workspace, $service, '2026-06-17T15:00:00Z', '2026-06-17T17:00:00Z');
        $bookingId = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'idem-party');
        $this->confirm($bookingId);

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $first,
            'idempotency_key' => 'move-once',
        ])->assertOk()->assertJsonPath('data.departure_id', $first);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $first,
            'idempotency_key' => 'move-once',
        ])->assertOk()->assertJsonPath('data.departure_id', $first);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $second,
            'idempotency_key' => 'move-once',
        ])->assertStatus(409)->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($first, Booking::query()->findOrFail($bookingId)->departure_id);
        $this->assertSame(1, Allocation::query()->where('booking_id', $bookingId)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.rescheduled')->count());
    }

    public function test_a_guest_picks_another_departure(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $open = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z');
        $full = $this->departure($workspace, $service, '2026-06-17T15:00:00Z', '2026-06-17T17:00:00Z', ['seats' => 2]);
        $bookingId = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 2, 'guest-tour', [
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ]);
        $this->confirm($bookingId);
        $filled = $this->party($workspace, $service, $full, '2026-06-17T15:00:00Z', '2026-06-17T17:00:00Z', 2, 'guest-full');
        $this->confirm($filled);
        app(TenantManager::class)->set($workspace['tenant']);
        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        app(TenantManager::class)->forget();

        $found = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $bookingId,
            'email' => 'ada@example.com',
        ])->assertRedirect();
        $page = $this->get((string) $found->headers->get('Location'))
            ->assertOk()
            ->assertSee('Tue, Jun 16, 3:00 PM – 5:00 PM')
            ->assertSee('Full')
            ->assertDontSee('Duration in minutes');
        preg_match('/action="([^"]*reschedule[^"]*)"/', (string) $page->getContent(), $action);
        $this->post(html_entity_decode($action[1]), [
            'departure_id' => $open,
        ])->assertOk()
            ->assertSee('Tue, Jun 16, 3:00 PM – 5:00 PM UTC')
            ->assertDontSee('2026-06-16T15:00:00');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($open, Booking::query()->findOrFail($bookingId)->departure_id);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.guest_rescheduled')->count());
    }

    public function test_staff_move_a_tour_from_the_booking_and_the_schedule(): void
    {
        [$workspace, $service, $asset] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $later = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z');
        $after = $this->departure($workspace, $service, '2026-06-18T15:00:00Z', '2026-06-18T17:00:00Z');
        $bookingId = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 2, 'staff-tour');
        $this->confirm($bookingId);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(BookingShow::class, ['booking' => $bookingId])
            ->assertSee('Tue, Jun 16, 3:00 PM – 5:00 PM')
            ->set('rescheduleDeparture', $later)
            ->call('reschedule')
            ->assertSee('Rescheduled.');

        $this->assertSame($later, Booking::query()->findOrFail($bookingId)->departure_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.rescheduled')->where('subject_id', $bookingId)->count());

        Livewire::test(Schedule::class)
            ->call('openMove', $bookingId, 'asset:'.$asset->id)
            ->assertSee('Thu, Jun 18, 3:00 PM – 5:00 PM')
            ->set('moveDeparture', $after)
            ->call('moveBooking')
            ->assertSee('Moved.');

        $this->assertSame($after, Booking::query()->findOrFail($bookingId)->departure_id);
        $this->assertSame('2026-06-18 15:00:00', Booking::query()->findOrFail($bookingId)->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.guest_rescheduled')->where('booking_id', $bookingId)->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'booking.rescheduled')->where('subject_id', $bookingId)->count());
    }

    public function test_a_guide_already_booked_on_an_overlapping_trip_is_refused(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $to = $this->departure($workspace, $service, '2026-06-15T18:00:00Z', '2026-06-15T20:00:00Z');
        $bookingId = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'guided', [
            'guide_user_id' => $workspace['user']->id,
        ]);
        $this->confirm($bookingId);
        app(TenantManager::class)->set($workspace['tenant']);
        $kayak = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Guide kayak',
        ]);
        $rental = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $service->location_id,
            'name' => 'Kayak rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $rental->id,
            'resource_type' => 'asset',
            'resource_id' => $kayak->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $rentalId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $rental->id,
            'starts_at' => '2026-06-15T18:00:00Z',
            'ends_at' => '2026-06-15T19:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'guide-rental',
            'guide_user_id' => $workspace['user']->id,
        ])->assertCreated()->json('data.id');
        $this->confirm($rentalId);

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'departure_id' => $to,
        ])->assertStatus(409)->assertJsonPath('message', 'That guide is already booked.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($from, Booking::query()->findOrFail($bookingId)->departure_id);
    }

    public function test_joining_an_occupied_departure_does_not_take_the_boat_again(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $to = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z');
        $mover = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'joins');
        $this->confirm($mover);
        $resident = $this->party($workspace, $service, $to, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z', 1, 'resident');
        $this->confirm($resident);

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$mover.'/reschedule'), [
            'departure_id' => $to,
        ])->assertOk()->assertJsonPath('data.departure_id', $to);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Allocation::query()->where('booking_id', $mover)->count());
        $this->assertSame(1, Allocation::query()->where('booking_id', $resident)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_a_payment_exception_party_moves_onto_a_free_departure_and_becomes_confirmed(): void
    {
        [$workspace, $service] = $this->tour();
        $from = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $free = $this->departure($workspace, $service, '2026-06-16T15:00:00Z', '2026-06-16T17:00:00Z');
        $full = $this->departure($workspace, $service, '2026-06-17T15:00:00Z', '2026-06-17T17:00:00Z', ['seats' => 1]);
        $lost = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'lost-tour');
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertGreaterThan(0, Hold::query()->where('booking_id', $lost)->update(['expires_at' => now()->subMinute()]));
        app(TenantManager::class)->forget();
        $occupant = $this->party($workspace, $service, $from, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 1, 'occupant-tour');
        $this->confirm($occupant);
        $filled = $this->party($workspace, $service, $full, '2026-06-17T15:00:00Z', '2026-06-17T17:00:00Z', 1, 'fills-tour');
        $this->confirm($filled);

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$lost.'/confirm'))
            ->assertStatus(409)
            ->assertJsonPath('data.status', 'payment_exception');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$lost.'/reschedule'), [
            'departure_id' => $full,
        ])->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('payment_exception', Booking::query()->findOrFail($lost)->status->value);
        $this->assertSame($from, Booking::query()->findOrFail($lost)->departure_id);
        $this->assertSame(0, Allocation::query()->where('booking_id', $lost)->count());
        $this->assertSame(1, Allocation::query()->where('booking_id', $occupant)->count());
        $this->assertSame(0, NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$lost)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$lost.'/reschedule'), [
            'departure_id' => $free,
        ])->assertOk()
            ->assertJsonPath('data.departure_id', $free)
            ->assertJsonPath('data.status', 'confirmed');

        app(TenantManager::class)->set($workspace['tenant']);
        $booking = Booking::query()->findOrFail($lost);
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertSame('2026-06-16 15:00:00', $booking->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, Allocation::query()->where('booking_id', $lost)->count());
        $this->assertSame($occupant, Allocation::query()->where('booking_id', $occupant)->sole()->booking_id);
        $this->assertSame('2026-06-15 15:00:00', Allocation::query()->where('booking_id', $occupant)->sole()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$lost)->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function departure(array $workspace, Service $service, string $start, string $end, array $extra = [], string $host = 'dock.test'): string
    {
        $this->actingAsWorkspace($workspace);

        return $this->postJson($this->tenantUrl($host, '/api/v1/departures'), array_merge([
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'seats' => 6,
        ], $extra))->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function party(array $workspace, Service $service, string $departureId, string $start, string $end, int $participants, string $key, array $extra = []): string
    {
        $this->actingAsWorkspace($workspace);

        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), array_merge([
            'service_id' => $service->id,
            'departure_id' => $departureId,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => $participants,
            'idempotency_key' => $key,
        ], $extra))->assertCreated()->json('data.id');
    }

    private function confirm(string $bookingId): void
    {
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function serviceFor(array $workspace, string $name): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $name.' boat',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $name,
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

        return $service;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service, 2: Asset}
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
            'name' => 'Harbor boat',
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

        return [$workspace, $service, $asset];
    }
}
