<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Enums\WaitlistStatus;
use App\Livewire\Staff\Closures;
use App\Livewire\Staff\Returns;
use App\Livewire\Staff\Team;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\RentalCheckout;
use App\Models\ReportSummary;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Inventory\ClosureWriter;
use App\Services\Platform\TenantExporter;
use App\Services\Reports\PortfolioReport;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\StaffLocationScope;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffLocationAccessTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_unrestricted_staff_member_still_sees_every_location(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $north = $this->rentalShop($alpha['tenant']);
        $south = $this->dock($alpha['tenant'], 'South dock');
        $when = CarbonImmutable::now('UTC')->addDays(2)->setTime(15, 0)->format('Y-m-d H:i:s');
        $this->booking($alpha['tenant'], $north['location'], $north['service'], 'North Guest', 'north-open@example.com', $when);
        $this->booking($alpha['tenant'], $south['location'], $south['service'], 'South Guest', 'south-open@example.com', $when);

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/calendar'))
            ->assertOk()
            ->assertSee('aria-label="Locations"', false)
            ->assertSee('North dock')
            ->assertSee('South dock')
            ->assertSee('All locations');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings'))
            ->assertOk()
            ->assertSee('North Guest')
            ->assertSee('South Guest');
    }

    public function test_an_owner_can_limit_a_member_and_invite_with_that_limit(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $staff = $this->member($alpha['tenant'], 'staff');
        $north = $this->rentalShop($alpha['tenant']);
        $south = $this->dock($alpha['tenant'], 'South dock');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);
        Notification::fake();

        Livewire::test(Team::class)
            ->set('memberAll.'.$staff['membership']->id, false)
            ->set('memberLocations.'.$staff['membership']->id, [$north['location']->id])
            ->call('saveLocations', $staff['membership']->id)
            ->assertHasNoErrors();

        $limited = $staff['membership']->fresh();
        $this->assertTrue($limited->locations_restricted);
        $this->assertSame(
            [$north['location']->id],
            MembershipLocation::query()->where('membership_id', $limited->id)->pluck('location_id')->all(),
        );
        $log = AuditLog::query()->where('action', 'membership.locations_updated')->firstOrFail();
        $this->assertFalse($log->properties['all_locations']);
        $this->assertSame([$north['location']->id], $log->properties['location_ids']);

        Livewire::test(Team::class)
            ->set('email', 'limited@example.com')
            ->set('role', 'staff')
            ->set('allLocations', false)
            ->set('locationIds', [])
            ->call('invite')
            ->assertHasErrors('locations');

        Livewire::test(Team::class)
            ->set('email', 'limited@example.com')
            ->set('role', 'staff')
            ->set('allLocations', false)
            ->set('locationIds', [$south['location']->id])
            ->call('invite')
            ->assertHasNoErrors();

        $invited = User::query()->where('email', 'limited@example.com')->firstOrFail();
        $membership = Membership::query()->where('user_id', $invited->id)->firstOrFail();
        $this->assertTrue($membership->locations_restricted);
        $this->assertSame(
            [$south['location']->id],
            MembershipLocation::query()->where('membership_id', $membership->id)->orderBy('location_id')->pluck('location_id')->all(),
        );
    }

    public function test_a_limited_member_is_denied_every_other_location(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test', 'owner');
        $staff = $this->member($alpha['tenant'], 'staff');
        $manager = $this->member($alpha['tenant'], 'manager');
        $north = $this->rentalShop($alpha['tenant']);
        $south = $this->dock($alpha['tenant'], 'South dock');
        $northBooking = $this->booking($alpha['tenant'], $north['location'], $north['service'], 'North Guest', 'north-guest@example.com', '2026-06-16 19:00:00');
        $southBooking = $this->booking($alpha['tenant'], $south['location'], $south['service'], 'South Guest', 'south-guest@example.com', '2026-06-16 19:00:00');
        $northAsset = Asset::factory()->create(['location_id' => $north['location']->id, 'name' => 'North boat']);
        $southAsset = Asset::factory()->create(['location_id' => $south['location']->id, 'name' => 'South boat']);
        $southPool = Pool::factory()->create(['location_id' => $south['location']->id, 'name' => 'South kayaks']);
        $northClosure = LocationClosure::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $north['location']->id,
            'reason' => 'North holiday',
        ]);
        $southClosure = LocationClosure::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $south['location']->id,
            'reason' => 'South holiday',
        ]);
        $northWait = $this->waitlist($alpha['tenant'], $north['location'], $north['service'], 'North Waiting');
        $southWait = $this->waitlist($alpha['tenant'], $south['location'], $south['service'], 'South Waiting');
        $northCheckout = $this->openCheckout($alpha['tenant'], $north['location'], $north['service'], 'North Return');
        $southCheckout = $this->openCheckout($alpha['tenant'], $south['location'], $south['service'], 'South Return');
        $this->payment($northBooking, 1100);
        $this->payment($southBooking, 5000);
        $this->fee($northBooking, 100);
        $this->fee($southBooking, 9000);
        $this->summary($north['location'], 100);
        $this->summary($south['location'], 9000);
        $beta = $this->workspace('beta.test');
        $betaShop = $this->dock($beta['tenant'], 'Beta dock');
        $betaBooking = $this->booking($beta['tenant'], $betaShop['location'], $betaShop['service'], 'Beta Guest', 'beta-guest@example.com');
        $this->limit($staff['membership'], $north['location']->id);
        $this->limit($manager['membership'], $north['location']->id);

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertFalse($staff['user']->can('view', $southBooking));
        $this->assertTrue($staff['user']->can('view', $northBooking));
        $this->assertFalse($staff['user']->can('view', $southAsset));

        $this->actingAsWorkspace($staff);
        $bookings = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/bookings'))->assertOk()->json('data');
        $bookingIds = array_column($bookings, 'id');
        $this->assertContains($northBooking->id, $bookingIds);
        $this->assertNotContains($southBooking->id, $bookingIds);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/bookings/'.$southBooking->id))->assertNotFound();
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/bookings/'.$betaBooking->id))->assertNotFound();
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/bookings/'.$northBooking->id))->assertOk();

        $assets = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/assets'))->assertOk()->json('data');
        $this->assertSame([$northAsset->id], array_column($assets, 'id'));
        $this->assertNotContains('South boat', array_column($assets, 'name'));
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/assets/'.$southAsset->id))->assertNotFound();
        $pools = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/pools'))->assertOk()->json('data');
        $this->assertNotContains($southPool->id, array_column($pools, 'id'));
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/pools/'.$southPool->id))->assertNotFound();

        $waiting = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/waitlist'))->assertOk()->json('data');
        $this->assertSame([$northWait->id], array_column($waiting, 'id'));
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/waitlist/'.$southWait->id.'/cancel'))->assertNotFound();
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/waitlist/'.$northWait->id.'/cancel'))->assertOk();

        $today = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/today?date=2026-06-16'))->assertOk()->json('data');
        $todayIds = array_column($today['bookings'], 'id');
        $this->assertContains($northBooking->id, $todayIds);
        $this->assertNotContains($southBooking->id, $todayIds);
        $this->assertSame(1100, $today['payments']['stripe_cents']);
        $this->assertSame(100, $today['fees']['charged_cents']);
        $this->assertSame(1, $today['open_checkouts']);
        $summaries = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/summaries?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->json('data');
        $this->assertSame([$north['location']->id], array_column($summaries, 'location_id'));
        $this->assertSame(100, $summaries[0]['gross_cents']);

        $this->actingAsWorkspace($manager);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/assets'), [
            'location_id' => $south['location']->id,
            'name' => 'Hidden boat',
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/assets'), [
            'location_id' => $north['location']->id,
            'name' => 'Allowed boat',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/locations'), [
            'name' => 'East dock',
            'timezone' => 'UTC',
        ])->assertForbidden();
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/blackouts'), [
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-02',
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/blackouts'), [
            'location_id' => $south['location']->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-02',
        ])->assertStatus(422)->assertJsonValidationErrors('location_id');

        $this->app['auth']->forgetGuards();
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($manager['user']);

        try {
            app(ClosureWriter::class)->save($alpha['tenant'], [
                'location_id' => $south['location']->id,
                'scope' => 'location',
                'reason' => 'Should fail',
                'span' => 'day',
                'yearly' => false,
                'starts_on' => '2026-08-01',
                'ends_on' => '2026-08-01',
            ]);
            $this->fail('A closure at another location was saved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('location_id', $exception->errors());
        }

        $this->app['auth']->forgetGuards();
        $this->actingAs($staff['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/calendar'))
            ->assertOk()
            ->assertSee('North dock')
            ->assertDontSee('South dock')
            ->assertDontSee('Beta dock');
        $this->get($this->tenantUrl('alpha.test', '/staff/calendar?location='.$south['location']->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/schedule?location='.$south['location']->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings'))
            ->assertOk()
            ->assertSee('North Guest')
            ->assertDontSee('South Guest')
            ->assertDontSee('Beta Guest');
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$southBooking->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/returns'))
            ->assertOk()
            ->assertSee('North Return')
            ->assertDontSee('South Return');
        $this->get($this->tenantUrl('alpha.test', '/staff/closures'))
            ->assertOk()
            ->assertSee('North holiday')
            ->assertDontSee('South holiday');
        $this->get($this->tenantUrl('alpha.test', '/staff/team'))->assertForbidden();

        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(Returns::class)
            ->call('recordReturn', $southCheckout['checkout']->id)
            ->assertNotFound();
        $this->assertNull($southCheckout['checkout']->fresh()->returned_at);

        Livewire::test(Closures::class)
            ->call('remove', $southClosure->id)
            ->assertNotFound();
        $this->assertNotNull(LocationClosure::withoutGlobalScope(StaffLocationScope::class)->find($southClosure->id));
        $this->assertNotNull(LocationClosure::query()->find($northClosure->id));
    }

    public function test_a_restricted_member_with_no_locations_sees_nothing(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $north = $this->rentalShop($alpha['tenant']);
        $when = CarbonImmutable::now('UTC')->addDays(2)->setTime(15, 0)->format('Y-m-d H:i:s');
        $this->booking($alpha['tenant'], $north['location'], $north['service'], 'North Guest', 'north-empty@example.com', $when);
        $alpha['membership']->locations_restricted = true;
        $alpha['membership']->save();
        app(StaffLocationAccess::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/bookings'))
            ->assertOk()
            ->assertDontSee('North Guest')
            ->assertDontSee('North dock');
    }

    public function test_a_guide_booked_at_another_location_still_blocks_a_hold(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner');
        $staff = $this->member($alpha['tenant'], 'staff');
        $north = $this->rentalShop($alpha['tenant']);
        $south = $this->dock($alpha['tenant'], 'South dock');
        $guide = User::factory()->create();
        $start = CarbonImmutable::now('UTC')->addDays(2)->setTime(15, 0);
        app(TenantManager::class)->set($alpha['tenant']);
        app(Allocator::class)->hold(new AllocationIntent(
            tenantId: $alpha['tenant']->id,
            serviceId: $south['service']->id,
            startsAt: $start,
            endsAt: $start->addHour(),
            participants: 2,
            idempotencyKey: null,
            customerName: 'South Guest',
            customerEmail: 'south-guide@example.com',
            guideUserId: $guide->id,
        ));
        $this->limit($staff['membership'], $north['location']->id);
        $this->actingAs($staff['user']);

        try {
            app(Allocator::class)->hold(new AllocationIntent(
                tenantId: $alpha['tenant']->id,
                serviceId: $north['service']->id,
                startsAt: $start,
                endsAt: $start->addHour(),
                participants: 2,
                idempotencyKey: null,
                customerName: 'North Guest',
                customerEmail: 'north-guide@example.com',
                guideUserId: $guide->id,
            ));
            $this->fail('The guide was booked twice.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That guide is already booked.', $exception->getMessage());
        }
    }

    public function test_a_repeated_idempotency_key_does_not_return_another_locations_booking(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner');
        $staff = $this->member($alpha['tenant'], 'staff');
        $north = $this->rentalShop($alpha['tenant']);
        $south = $this->dock($alpha['tenant'], 'South dock');
        $start = CarbonImmutable::now('UTC')->addDays(2)->setTime(15, 0);
        app(TenantManager::class)->set($alpha['tenant']);
        $existing = app(Allocator::class)->hold(new AllocationIntent(
            tenantId: $alpha['tenant']->id,
            serviceId: $south['service']->id,
            startsAt: $start,
            endsAt: $start->addHour(),
            participants: 2,
            idempotencyKey: 'shared-key',
            customerName: 'South Guest',
            customerEmail: 'south-key@example.com',
        ));
        $this->limit($staff['membership'], $north['location']->id);
        $this->actingAs($staff['user']);

        try {
            app(Allocator::class)->hold(new AllocationIntent(
                tenantId: $alpha['tenant']->id,
                serviceId: $north['service']->id,
                startsAt: $start,
                endsAt: $start->addHour(),
                participants: 2,
                idempotencyKey: 'shared-key',
                customerName: 'North Guest',
                customerEmail: 'north-key@example.com',
            ));
            $this->fail('The other location booking was returned.');
        } catch (IdempotencyConflictException) {
            $this->assertSame(
                1,
                Booking::withoutGlobalScope(StaffLocationScope::class)->where('idempotency_key', 'shared-key')->count(),
            );
            $this->assertSame($existing->id, Booking::withoutGlobalScope(StaffLocationScope::class)->where('idempotency_key', 'shared-key')->value('id'));
        }
    }

    public function test_portfolio_keeps_other_businesses_and_drops_hidden_locations(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $beta = $this->workspace('beta.test', 'owner', ['name' => 'Beta Boats']);
        Membership::factory()->role('owner')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);
        $north = $this->dock($alpha['tenant'], 'North dock');
        $south = $this->dock($alpha['tenant'], 'South dock');
        $betaDock = $this->dock($beta['tenant'], 'Beta dock');
        $this->booking($alpha['tenant'], $north['location'], $north['service'], 'North Guest', 'north-port@example.com', '2026-10-15 15:00:00');
        $this->booking($alpha['tenant'], $south['location'], $south['service'], 'South Guest', 'south-port@example.com', '2026-10-15 15:00:00');
        $this->booking($beta['tenant'], $betaDock['location'], $betaDock['service'], 'Beta Guest', 'beta-port@example.com', '2026-10-15 15:00:00');
        $this->limit($alpha['membership'], $north['location']->id);
        $this->actingAs($alpha['user']);
        app(TenantManager::class)->set($alpha['tenant']);

        $report = app(PortfolioReport::class)->build(
            collect([$alpha['tenant'], $beta['tenant']]),
            '2026-10-01',
            '2026-10-31',
        );

        $counts = [];

        foreach ($report['businesses'] as $business) {
            $counts[$business['name']] = $business['bookings'];
        }

        $this->assertSame(2, $report['totals']['bookings']);
        $this->assertSame(1, $counts['Alpha Kayaks']);
        $this->assertSame(1, $counts['Beta Boats']);
    }

    public function test_a_workspace_export_includes_every_location(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $north = $this->dock($alpha['tenant'], 'North dock');
        $south = $this->dock($alpha['tenant'], 'South dock');
        $this->limit($alpha['membership'], $north['location']->id);
        Storage::fake('local');
        $this->actingAs($alpha['user']);

        $export = app(TenantExporter::class)->create($alpha['tenant'], $alpha['user']);
        $json = Storage::disk('local')->get($export->path);

        $this->assertIsString($json);
        $this->assertStringContainsString('North dock', $json);
        $this->assertStringContainsString('South dock', $json);
        $this->assertStringContainsString('membership_locations', $json);
        $this->assertSame(2, $export->row_counts['locations']);
    }

    public function test_customer_privacy_is_refused_while_locations_are_limited(): void
    {
        $alpha = $this->workspace('alpha.test', 'manager');
        $open = $this->member($alpha['tenant'], 'manager');
        $north = $this->dock($alpha['tenant'], 'North dock');
        $this->limit($alpha['membership'], $north['location']->id);
        $customer = Customer::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
        ]);
        Queue::fake();

        $this->actingAsWorkspace($alpha);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/customers/'.$customer->id.'/exports'))->assertForbidden();
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/customers/'.$customer->id.'/deletion'), [
            'confirm' => true,
        ])->assertForbidden();

        $this->actingAsWorkspace($open);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/customers/'.$customer->id.'/exports'))->assertStatus(202);
    }

    /**
     * @return array{user: User, membership: Membership}
     */
    private function member(Tenant $tenant, string $role): array
    {
        $user = User::factory()->create();
        $membership = Membership::factory()->role($role)->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        return compact('user', 'membership');
    }

    private function limit(Membership $membership, string ...$locationIds): void
    {
        $membership->locations_restricted = true;
        $membership->save();

        foreach ($locationIds as $locationId) {
            MembershipLocation::query()->create([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'location_id' => $locationId,
            ]);
        }

        app(StaffLocationAccess::class)->forget();
    }

    /**
     * @return array{location: Location, service: Service}
     */
    private function dock(Tenant $tenant, string $name): array
    {
        app(TenantManager::class)->set($tenant);
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => 'America/New_York',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name.' kayaks',
            'quantity' => 4,
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name.' rental',
            'kind' => ServiceKind::Rental,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => 'pool',
            'resource_id' => $pool->id,
            'quantity' => 1,
            'per_participants' => 1,
        ]);
        ServiceDuration::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'name' => '1 hour',
            'quantity' => 1,
            'unit' => 'hours',
            'duration_minutes' => 60,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'name' => 'Launch',
            'kind' => 'base',
            'amount_cents' => 2500,
            'window' => 'always',
        ]);

        return ['location' => $location, 'service' => $service];
    }

    private function booking(Tenant $tenant, Location $location, Service $service, string $name, string $email, string $startsAt = '2026-06-16 15:00:00'): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt, 'UTC')->addHour(),
            'participants' => 2,
            'payload_hash' => hash('sha256', $email.$startsAt),
        ]);
    }

    private function waitlist(Tenant $tenant, Location $location, Service $service, string $name): WaitlistEntry
    {
        app(TenantManager::class)->set($tenant);

        return WaitlistEntry::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 2,
            'customer_name' => $name,
            'customer_email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'status' => WaitlistStatus::Waiting,
        ]);
    }

    /**
     * @return array{checkout: RentalCheckout}
     */
    private function openCheckout(Tenant $tenant, Location $location, Service $service, string $name): array
    {
        app(TenantManager::class)->set($tenant);
        $booking = $this->booking($tenant, $location, $service, $name, strtolower(str_replace(' ', '.', $name)).'@example.com');
        $checkout = RentalCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'quantity' => 1,
            'checked_out_at' => '2026-06-16 15:00:00',
            'due_at' => '2026-06-16 17:00:00',
        ]);

        return compact('checkout');
    }

    private function payment(Booking $booking, int $cents): void
    {
        app(TenantManager::class)->set($booking->tenant);
        $payment = Payment::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'balance',
            'status' => 'paid',
            'amount_cents' => $cents,
        ]);
        $payment->created_at = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        $payment->save();
    }

    private function fee(Booking $booking, int $cents): void
    {
        app(TenantManager::class)->set($booking->tenant);
        PlatformFee::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'amount_cents' => $cents,
            'channel' => 'direct',
        ]);
    }

    private function summary(Location $location, int $cents): void
    {
        app(TenantManager::class)->set($location->tenant);
        ReportSummary::query()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'summary_date' => '2026-06-16',
            'available_resource_minutes' => 60,
            'allocated_resource_minutes' => 30,
            'bookings_created' => 1,
            'bookings_converted' => 1,
            'gross_cents' => $cents,
        ]);
    }
}
