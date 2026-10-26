<?php

namespace Tests\Feature\Booking;

use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\NewBooking;
use App\Livewire\Staff\Schedule;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GuideAssignmentTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_can_name_a_guide_while_holding_a_booking(): void
    {
        $workspace = $this->signedIn();
        $workspace['user']->forceFill(['name' => 'Ada Guide'])->save();
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $sam = $this->member($workspace, 'Sam Helm');
        $revoked = $this->member($workspace, 'Revoked Pilot', revoked: true);
        $other = $this->workspace('other.test');
        $other['user']->forceFill(['name' => 'Other Harbor'])->save();
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(15, 0);

        app(TenantManager::class)->set($workspace['tenant']);
        Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->set('date', $start->toDateString())
            ->set('startsAt', $start->toIso8601String())
            ->set('customerName', 'Ada Lovelace')
            ->set('guideUserId', $workspace['user']->id)
            ->assertSee('Sam Helm')
            ->assertSee('Ada Guide')
            ->assertDontSee('Revoked Pilot')
            ->assertDontSee('Other Harbor')
            ->call('book')
            ->assertHasNoErrors()
            ->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $ada = Booking::query()->where('guide_user_id', $workspace['user']->id)->firstOrFail();
        $this->assertSame('Ada Lovelace', $ada->customer()->value('name'));

        $later = $start->addHours(3);
        Livewire::test(Schedule::class)
            ->call('openSlot', 'pool:'.$shop['pool']->id, $later->toDateString(), '18:00')
            ->assertSee('Sam Helm')
            ->assertDontSee('Revoked Pilot')
            ->assertDontSee('Other Harbor')
            ->set('bookCustomerName', 'Grace Hopper')
            ->set('bookGuide', $sam->id)
            ->call('holdBooking')
            ->assertHasNoErrors()
            ->assertRedirect();

        app(TenantManager::class)->set($workspace['tenant']);
        $grace = Booking::query()->where('guide_user_id', $sam->id)->firstOrFail();
        $this->assertNotSame($ada->id, $grace->id);
        $this->assertNotSame($revoked->id, $grace->guide_user_id);
        $this->assertNotSame($other['user']->id, $ada->guide_user_id);

        $this->actingAsWorkspace($workspace);
        $openId = $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings'), [
            'service_id' => $shop['service']->id,
            'starts_at' => $start->addMinutes(30)->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $start->addMinutes(90)->utc()->format('Y-m-d\TH:i:s\Z'),
            'participants' => 1,
            'customer_name' => 'Open Guest',
            'idempotency_key' => 'open-guest',
        ])->assertCreated()->assertJsonPath('data.guide_user_id', null)->json('data.id');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$openId))
            ->assertOk()
            ->assertSee('Save guide')
            ->assertSee('Ada Guide')
            ->assertSee('Sam Helm')
            ->assertDontSee('Revoked Pilot')
            ->assertDontSee('Other Harbor')
            ->assertSee('data-guide="none"', false)
            ->assertSee('The price stays the same.');

        app(TenantManager::class)->set($workspace['tenant']);
        Livewire::test(BookingShow::class, ['booking' => $openId])
            ->set('guideUserId', $workspace['user']->id)
            ->call('assignGuide')
            ->assertSee('That guide is already booked.')
            ->assertSee('data-guide="none"', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertNull(Booking::query()->findOrFail($openId)->guide_user_id);
        $this->assertSame($workspace['user']->id, $ada->fresh()->guide_user_id);

        Livewire::test(BookingShow::class, ['booking' => $openId])
            ->set('guideUserId', $sam->id)
            ->call('assignGuide')
            ->assertSee('Guide saved.')
            ->assertSee('Sam Helm')
            ->assertSee('data-guide="'.$sam->id.'"', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($sam->id, Booking::query()->findOrFail($openId)->guide_user_id);

        Livewire::test(BookingShow::class, ['booking' => $openId])
            ->set('guideUserId', '')
            ->call('assignGuide')
            ->assertSee('Guide saved.')
            ->assertSee('No guide')
            ->assertSee('data-guide="none"', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertNull(Booking::query()->findOrFail($openId)->guide_user_id);
        $this->assertSame($sam->id, $grace->fresh()->guide_user_id);

        $css = (string) file_get_contents(resource_path('ui/app.css'));
        $this->assertStringContainsString('.facts dd { margin: 0; font-weight: 600; min-width: 0; overflow-wrap: anywhere; }', $css);
        $this->assertMatchesRegularExpression('/select, textarea \{\s*font: inherit;.*?width: 100%;/s', $css);
    }

    public function test_a_member_who_cannot_manage_bookings_cannot_assign_a_guide(): void
    {
        $workspace = $this->signedIn('staff');
        $shop = $this->rentalShop($workspace['tenant'], 2, 'UTC');
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings'), [
            'service_id' => $shop['service']->id,
            'starts_at' => '2026-06-18T15:00:00Z',
            'ends_at' => '2026-06-18T16:00:00Z',
            'participants' => 1,
            'customer_name' => 'Staff Guest',
            'idempotency_key' => 'staff-hold',
        ])->assertCreated()->json('data.id');

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $permission = Permission::query()->where('slug', 'bookings.manage')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->actingAs($workspace['user']);
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$bookingId))
            ->assertOk()
            ->assertDontSee('Save guide')
            ->assertSee('No guide');

        app(TenantManager::class)->set($workspace['tenant']);
        Livewire::test(BookingShow::class, ['booking' => $bookingId])
            ->set('guideUserId', $workspace['user']->id)
            ->call('assignGuide')
            ->assertForbidden();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertNull(Booking::query()->findOrFail($bookingId)->guide_user_id);
    }

    public function test_assigning_a_guide_keeps_the_price_and_shares_a_departure(): void
    {
        [$workspace, $service] = $this->tour();
        $sam = $this->member($workspace, 'Sam Helm');
        $departure = $this->departure($workspace, $service, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z');
        $holder = $this->party($workspace, $service, $departure, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 'holder');
        $guest = $this->party($workspace, $service, $departure, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 'guest');
        $leaving = $this->party($workspace, $service, $departure, '2026-06-15T15:00:00Z', '2026-06-15T17:00:00Z', 'leaving');
        $this->confirm($holder);
        $this->confirm($guest);
        $this->confirm($leaving);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$leaving.'/cancel'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $allocationId = Allocation::query()->where('booking_id', $holder)->sole()->id;
        PricingSnapshot::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $holder,
            'currency' => 'usd',
            'payload' => ['customer_total_cents' => 4000, 'marker' => 'stay'],
            'rules_version' => 'test',
        ]);
        $rental = $this->rentalService($workspace, $service->location_id);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $rentalId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $rental->id,
            'starts_at' => '2026-06-15T15:30:00Z',
            'ends_at' => '2026-06-15T16:30:00Z',
            'participants' => 1,
            'idempotency_key' => 'guide-rental',
            'guide_user_id' => $workspace['user']->id,
        ])->assertCreated()->json('data.id');
        $this->confirm($rentalId);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$holder.'/guide'), [
            'guide_user_id' => $sam->id,
        ])->assertOk()->assertJsonPath('data.guide_user_id', $sam->id);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($sam->id, Booking::query()->findOrFail($holder)->guide_user_id);
        $this->assertSame($sam->id, Booking::query()->findOrFail($guest)->guide_user_id);
        $this->assertNull(Booking::query()->findOrFail($leaving)->guide_user_id);
        $this->assertSame($allocationId, Allocation::query()->where('booking_id', $holder)->sole()->id);
        $this->assertSame('stay', PricingSnapshot::query()->where('booking_id', $holder)->sole()->payload['marker']);
        $this->assertSame(4000, PricingSnapshot::query()->where('booking_id', $holder)->sole()->payload['customer_total_cents']);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'booking.guide_assigned')->count());

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$guest.'/guide'), [
            'guide_user_id' => $workspace['user']->id,
        ])->assertStatus(409)->assertJsonPath('message', 'That guide is already booked.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($sam->id, Booking::query()->findOrFail($holder)->guide_user_id);
        $this->assertSame($sam->id, Booking::query()->findOrFail($guest)->guide_user_id);
        $this->assertSame($workspace['user']->id, Booking::query()->findOrFail($rentalId)->guide_user_id);
        $this->assertSame(2, AuditLog::query()->where('action', 'booking.guide_assigned')->count());
    }

    public function test_the_api_refuses_a_guide_who_cannot_take_that_booking(): void
    {
        [$workspace, $service] = $this->rental();
        $revoked = $this->member($workspace, 'Revoked Pilot', revoked: true);
        $this->actingAsWorkspace($workspace);
        $open = $this->hold($service, '2026-06-16T15:00:00Z', '2026-06-16T16:00:00Z', 'open');
        $expired = $this->hold($service, '2026-06-16T17:00:00Z', '2026-06-16T18:00:00Z', 'expired');
        $cancelled = $this->hold($service, '2026-06-16T19:00:00Z', '2026-06-16T20:00:00Z', 'cancelled');

        app(TenantManager::class)->set($workspace['tenant']);
        Hold::query()->where('booking_id', $expired)->update(['expires_at' => now()->subMinute()]);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$cancelled.'/cancel'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$open.'/guide'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('guide_user_id');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$open.'/guide'), [
            'guide_user_id' => $revoked->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('guide_user_id')
            ->assertJsonPath('errors.guide_user_id.0', 'That guide is not an active member of this workspace.');

        $outsider = User::factory()->create();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$open.'/guide'), [
            'guide_user_id' => $outsider->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('guide_user_id');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$expired.'/guide'), [
            'guide_user_id' => $workspace['user']->id,
        ])->assertStatus(409)->assertJsonPath('message', 'That hold has expired.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$cancelled.'/guide'), [
            'guide_user_id' => $workspace['user']->id,
        ])->assertStatus(409)->assertJsonPath('message', 'Only a held or confirmed booking can be assigned a guide.');

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$open.'/guide'), [
            'guide_user_id' => $other['user']->id,
        ])->assertNotFound();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertNull(Booking::query()->findOrFail($open)->guide_user_id);
        $this->assertNull(Booking::query()->findOrFail($expired)->guide_user_id);
        $this->assertNull(Booking::query()->findOrFail($cancelled)->guide_user_id);
    }

    public function test_an_idempotency_key_replays_the_first_guide_assignment(): void
    {
        [$workspace, $service] = $this->rental();
        $sam = $this->member($workspace, 'Sam Helm');
        $revoked = $this->member($workspace, 'Revoked Pilot', revoked: true);
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, '2026-06-16T15:00:00Z', '2026-06-16T16:00:00Z', 'idempotent');
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/guide');

        $this->postJson($url, [
            'guide_user_id' => $revoked->id,
        ], ['Idempotency-Key' => 'guide-key'])->assertUnprocessable();

        $this->postJson($url, [
            'guide_user_id' => $sam->id,
        ], ['Idempotency-Key' => 'guide-key'])->assertOk()->assertJsonPath('data.guide_user_id', $sam->id);

        $this->postJson($url, [
            'guide_user_id' => $sam->id,
        ], ['Idempotency-Key' => 'guide-key'])->assertOk()->assertJsonPath('data.guide_user_id', $sam->id);

        $this->postJson($url, [
            'guide_user_id' => $workspace['user']->id,
        ], ['Idempotency-Key' => 'guide-key'])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $this->postJson($url, [
            'guide_user_id' => null,
        ])->assertOk()->assertJsonPath('data.guide_user_id', null);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertNull(Booking::query()->findOrFail($bookingId)->guide_user_id);
        $this->assertSame(2, AuditLog::query()->where('action', 'booking.guide_assigned')->where('subject_id', $bookingId)->count());
    }

    public function test_the_dispatch_sheet_names_the_guide(): void
    {
        [$workspace, $service] = $this->rental();
        $workspace['user']->forceFill(['name' => 'Ada Guide'])->save();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, '2026-06-15T15:00:00Z', '2026-06-15T16:00:00Z', 'manifest', 'River Quinn');
        $this->confirm($bookingId);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/guide'), [
            'guide_user_id' => $workspace['user']->id,
        ])->assertOk();

        $other = $this->workspace('other.test');
        $otherService = $this->serviceFor($other, 'Other rental', 'rental');
        $this->actingAsWorkspace($other);
        $hidden = $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings'), [
            'service_id' => $otherService->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'customer_name' => 'Other Guest',
            'idempotency_key' => 'other-manifest',
        ])->assertCreated()->json('data.id');
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$hidden.'/confirm'))->assertOk();

        $this->actingAs($workspace['user']);
        $page = $this->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-06-15'))
            ->assertOk()
            ->assertSee('River Quinn')
            ->assertSee('Ada Guide')
            ->assertDontSee('Other Guest');
        $this->assertStringContainsString('>Guide<', (string) $page->getContent());

        $rows = $this->byId($this->rows((string) $this->get($this->tenantUrl('dock.test', '/staff/manifest.csv?date=2026-06-15'))->assertOk()->getContent()));
        $this->assertSame('Ada Guide', $rows[$bookingId]['guide']);
        $this->assertArrayNotHasKey($hidden, $rows);
        $header = array_keys($rows[$bookingId]);
        $this->assertSame('guide', $header[array_search('resource', $header, true) + 1]);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function signedIn(string $role = 'owner'): array
    {
        $workspace = $this->workspace('alpha.test', $role);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function member(array $workspace, string $name, bool $revoked = false): User
    {
        $user = User::factory()->create(['name' => $name]);
        $factory = Membership::factory()->role('staff');

        if ($revoked) {
            $factory = $factory->revoked();
        }

        app(TenantManager::class)->set($workspace['tenant']);
        $factory->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $user->id,
        ]);

        return $user;
    }

    private function hold(Service $service, string $start, string $end, string $key, string $customer = 'Guest'): string
    {
        $id = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'customer_name' => $customer,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($id);

        return $id;
    }

    private function confirm(string $bookingId): void
    {
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function departure(array $workspace, Service $service, string $start, string $end): string
    {
        $this->actingAsWorkspace($workspace);

        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/departures'), [
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'seats' => 6,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function party(array $workspace, Service $service, string $departureId, string $start, string $end, string $key): string
    {
        $this->actingAsWorkspace($workspace);

        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'departure_id' => $departureId,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function rentalService(array $workspace, string $locationId): Service
    {
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $locationId,
            'name' => 'Spare kayak',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $locationId,
            'name' => 'Spare rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);

        return $service;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(): array
    {
        $workspace = $this->workspace('dock.test');
        $service = $this->serviceFor($workspace, 'Hourly boat', 'rental');

        return [$workspace, $service];
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function tour(): array
    {
        $workspace = $this->workspace('dock.test');

        return [$workspace, $this->serviceFor($workspace, 'Harbor tour', 'tour')];
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function serviceFor(array $workspace, string $name, string $kind): Service
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
        $attributes = [
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => $kind,
        ];

        if ($kind === 'tour') {
            $attributes['max_participants'] = 6;
        }

        $service = Service::query()->create($attributes);
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
     * @return list<array<int, string|null>>
     */
    private function rows(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<int, string|null>>  $rows
     * @return array<string, array<string, string>>
     */
    private function byId(array $rows): array
    {
        $header = array_shift($rows);
        $this->assertIsArray($header);
        $indexed = [];

        foreach ($rows as $row) {
            $assoc = [];

            foreach ($header as $index => $column) {
                if (! is_string($column)) {
                    continue;
                }

                $value = $row[$index] ?? '';
                $assoc[$column] = is_string($value) ? $value : '';
            }

            $indexed[$assoc['booking_id']] = $assoc;
        }

        return $indexed;
    }
}
