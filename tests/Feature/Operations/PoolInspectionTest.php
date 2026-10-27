<?php

namespace Tests\Feature\Operations;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Staff\Maintenance;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\InspectionSchedule;
use App\Models\Location;
use App\Models\MaintenanceRecord;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PoolInspectionTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_day_based_pool_inspection_records_the_pool_and_schedules_the_next_one(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $money = $this->moneyState();
        $this->actingAs($alpha['user']);

        Livewire::test(Maintenance::class)
            ->set('inspectionResource', 'pool:'.$shop['pool']->id)
            ->set('inspectionName', 'Dock line')
            ->set('inspectionEvery', '30')
            ->set('inspectionUnit', 'days')
            ->call('addInspection')
            ->assertHasNoErrors()
            ->assertSee('Inspection added for Single kayak.');

        $schedule = InspectionSchedule::query()->where('name', 'Dock line')->sole();
        $this->assertNull($schedule->asset_id);
        $this->assertSame($shop['pool']->id, $schedule->pool_id);
        $this->assertSame('2026-07-31 15:00:00', $schedule->next_due_at?->utc()->format('Y-m-d H:i:s'));
        $scheduled = AuditLog::query()->where('action', 'inspection.scheduled')->where('subject_id', $schedule->id)->sole();
        $this->assertNull($scheduled->properties['asset_id'] ?? null);
        $this->assertSame($shop['pool']->id, $scheduled->properties['pool_id'] ?? null);

        Carbon::setTestNow(Carbon::parse('2026-08-02 13:00:00', 'UTC'));
        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(Maintenance::class)
            ->assertSee('Single kayak (pool)')
            ->assertSee('Due')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('.maintenance-page .inspection-row *', false)
            ->set('doneNote.'.$schedule->id, 'Lines sound')
            ->call('markDone', $schedule->id)
            ->assertHasNoErrors()
            ->assertSee('Dock line done. Next due Sep 1, 2026.');

        $schedule->refresh();
        $record = MaintenanceRecord::query()->sole();
        $this->assertNull($record->asset_id);
        $this->assertSame($shop['pool']->id, $record->pool_id);
        $this->assertSame('Dock line: Lines sound', $record->summary);
        $this->assertSame('2026-09-01 13:00:00', $schedule->next_due_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('active', $shop['pool']->fresh()->status->value);
        $done = AuditLog::query()->where('action', 'inspection.done')->where('subject_id', $schedule->id)->sole();
        $this->assertSame($shop['pool']->id, $done->properties['pool_id'] ?? null);
        $this->assertSame($record->id, $done->properties['maintenance_record_id'] ?? null);
        $this->assertSame($money, $this->moneyState());
    }

    public function test_a_use_based_pool_inspection_counts_finished_rentals_on_that_pool_only(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $other = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Tandem kayak',
        ]);
        $this->actingAs($alpha['user']);

        Livewire::test(Maintenance::class)
            ->set('inspectionResource', 'pool:'.$shop['pool']->id)
            ->set('inspectionName', 'Wear check')
            ->set('inspectionEvery', '2')
            ->set('inspectionUnit', 'uses')
            ->call('addInspection')
            ->assertHasNoErrors();

        $schedule = InspectionSchedule::query()->where('name', 'Wear check')->sole();
        $this->assertNull($schedule->next_due_at);
        $this->assertSame(2, $schedule->interval_uses);

        Carbon::setTestNow(Carbon::parse('2026-07-03 15:00:00', 'UTC'));
        app(TenantManager::class)->set($alpha['tenant']);
        $first = $this->finishedRental($alpha['tenant'], $shop['location'], $shop['service'], $shop['pool'], BookingStatus::Confirmed, '2026-07-02 16:00:00');
        $second = $this->finishedRental($alpha['tenant'], $shop['location'], $shop['service'], $shop['pool'], BookingStatus::Confirmed, '2026-07-02 18:00:00');
        $held = $this->finishedRental($alpha['tenant'], $shop['location'], $shop['service'], $shop['pool'], BookingStatus::Held, '2026-07-02 17:00:00');
        $elsewhere = $this->finishedRental($alpha['tenant'], $shop['location'], $shop['service'], $other, BookingStatus::Confirmed, '2026-07-02 16:00:00');
        $allocations = Allocation::query()->count();
        $money = $this->moneyState();

        Livewire::test(Maintenance::class)
            ->assertSee('Due')
            ->assertSee('2 since last done')
            ->call('markDone', $schedule->id)
            ->assertHasNoErrors()
            ->assertSee('Wear check done. The count of uses starts again now.')
            ->assertDontSee('Due');

        $schedule->refresh();
        $record = MaintenanceRecord::query()->sole();
        $this->assertNull($record->asset_id);
        $this->assertSame($shop['pool']->id, $record->pool_id);
        $this->assertSame('Wear check', $record->summary);
        $this->assertNull($schedule->next_due_at);
        $this->assertSame('2026-07-03 15:00:00', $schedule->last_done_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('confirmed', $first->fresh()->status->value);
        $this->assertSame('confirmed', $second->fresh()->status->value);
        $this->assertSame('held', $held->fresh()->status->value);
        $this->assertSame('confirmed', $elsewhere->fresh()->status->value);
        $this->assertSame($allocations, Allocation::query()->count());
        $this->assertSame($shop['pool']->id, Allocation::query()->where('booking_id', $first->id)->value('pool_id'));
        $this->assertSame('active', $shop['pool']->fresh()->status->value);
        $this->assertSame($money, $this->moneyState());
    }

    public function test_the_page_lists_a_pools_inspections_only_when_that_pool_is_selected(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $boat = Asset::factory()->create(['location_id' => $shop['location']->id, 'name' => 'Pontoon 1']);
        $this->actingAs($alpha['user']);

        Livewire::test(Maintenance::class)
            ->set('inspectionResource', 'pool:'.$shop['pool']->id)
            ->set('inspectionName', 'Kayak dock line')
            ->call('addInspection')
            ->assertHasNoErrors()
            ->set('inspectionResource', 'asset:'.$boat->id)
            ->set('inspectionName', 'Pontoon hull')
            ->call('addInspection')
            ->assertHasNoErrors()
            ->set('resource', 'pool:'.$shop['pool']->id)
            ->assertSee('Kayak dock line')
            ->assertDontSee('Pontoon hull')
            ->set('resource', 'asset:'.$boat->id)
            ->assertSee('Pontoon hull')
            ->assertDontSee('Kayak dock line');
    }

    public function test_another_workspace_or_a_location_the_member_cannot_use_cannot_inspect_the_pool(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $north = $this->rentalShop($alpha['tenant']);
        $south = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'South dock',
            'timezone' => 'America/New_York',
        ]);
        $southPool = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South kayaks',
        ]);
        $southCheck = InspectionSchedule::factory()->forPool($southPool)->create(['name' => 'South pool check']);
        app(TenantManager::class)->forget();

        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);
        Livewire::test(Maintenance::class)
            ->set('inspectionResource', 'pool:'.$southPool->id)
            ->set('inspectionName', 'Takeover')
            ->call('addInspection')
            ->assertNotFound();
        Livewire::test(Maintenance::class)->call('markDone', $southCheck->id)->assertNotFound();

        $manager = $this->member($alpha['tenant'], 'manager');
        $this->limit($manager['membership'], $north['location']->id);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($manager['user']);
        Livewire::test(Maintenance::class)
            ->assertDontSee('South pool check')
            ->set('inspectionResource', 'pool:'.$southPool->id)
            ->set('inspectionName', 'Sneaky check')
            ->call('addInspection')
            ->assertNotFound();
        Livewire::test(Maintenance::class)->call('markDone', $southCheck->id)->assertNotFound();

        $staff = $this->member($alpha['tenant'], 'staff');
        app(StaffLocationAccess::class)->forget();
        $this->actingAs($staff['user']);
        Livewire::test(Maintenance::class)
            ->assertDontSee('Add an inspection')
            ->set('inspectionResource', 'pool:'.$north['pool']->id)
            ->set('inspectionName', 'Not allowed')
            ->call('addInspection')
            ->assertForbidden();
        Livewire::test(Maintenance::class)->call('markDone', $southCheck->id)->assertForbidden();

        $frontDesk = Role::query()->create(['slug' => 'front-desk', 'name' => 'Front desk']);
        $frontDesk->permissions()->sync([Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id]);
        $staff['membership']->update(['role_id' => $frontDesk->id]);
        app(TenantManager::class)->forget();
        $this->actingAs($staff['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/maintenance'))
            ->assertForbidden();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(1, InspectionSchedule::query()->count());
        $this->assertSame(0, MaintenanceRecord::query()->count());
        $this->assertSame('active', $southPool->fresh()->status->value);
    }

    public function test_the_pool_maintenance_record_api_lists_that_pool_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant'], timezone: 'UTC');
        $other = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Tandem kayak',
        ]);
        $boat = Asset::factory()->create([
            'location_id' => $shop['location']->id,
            'name' => 'Pontoon 1',
        ]);
        app(TenantManager::class)->forget();
        $money = $this->moneyState();
        $this->actingAsWorkspace($workspace);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id.'/maintenance-records'), [
            'inspected_at' => '2026-06-16T11:00:00Z',
            'summary' => 'Kayaks are sound.',
        ])->assertCreated()
            ->assertJsonPath('data.asset_id', null)
            ->assertJsonPath('data.pool_id', $shop['pool']->id)
            ->assertJsonPath('data.summary', 'Kayaks are sound.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$boat->id.'/maintenance-records'), [
            'inspected_at' => '2026-06-16T11:30:00Z',
            'summary' => 'Hull is sound.',
        ])->assertCreated();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id.'/maintenance-records'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.pool_id', $shop['pool']->id)
            ->assertJsonPath('data.0.asset_id', null)
            ->assertJsonPath('data.0.summary', 'Kayaks are sound.');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$other->id.'/maintenance-records'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$boat->id.'/maintenance-records'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.summary', 'Hull is sound.')
            ->assertJsonPath('data.0.pool_id', null);

        $viewer = $this->member($workspace['tenant'], 'staff');
        $this->actingAsWorkspace(['user' => $viewer['user']]);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id.'/maintenance-records'))
            ->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id.'/maintenance-records'), [
            'inspected_at' => '2026-06-16T12:00:00Z',
            'summary' => 'Not allowed.',
        ])->assertForbidden();

        $limited = $this->member($workspace['tenant'], 'manager');
        $south = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South dock',
            'timezone' => 'UTC',
        ]);
        $this->limit($limited['membership'], $south->id);
        $this->actingAsWorkspace(['user' => $limited['user']]);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id.'/maintenance-records'))
            ->assertNotFound();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id.'/maintenance-records'), [
            'inspected_at' => '2026-06-16T12:00:00Z',
            'summary' => 'Wrong dock.',
        ])->assertNotFound();

        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($beta);
        $this->getJson($this->tenantUrl('beta.test', '/api/v1/pools/'.$shop['pool']->id.'/maintenance-records'))
            ->assertNotFound();
        $this->postJson($this->tenantUrl('beta.test', '/api/v1/pools/'.$shop['pool']->id.'/maintenance-records'), [
            'inspected_at' => '2026-06-16T12:00:00Z',
            'summary' => 'Other workspace.',
        ])->assertNotFound();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, MaintenanceRecord::query()->count());
        $this->assertSame('active', $shop['pool']->fresh()->status->value);
        $this->assertSame($money, $this->moneyState());
        app(TenantManager::class)->forget();
    }

    public function test_a_maintenance_record_cannot_omit_both_resources(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);

        $this->expectException(QueryException::class);

        MaintenanceRecord::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'asset_id' => null,
            'pool_id' => null,
            'inspected_at' => '2026-07-01 15:00:00',
            'summary' => 'Neither',
        ]);
    }

    public function test_an_inspection_cannot_name_a_boat_and_a_pool(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $boat = Asset::factory()->create(['location_id' => $shop['location']->id, 'name' => 'Pontoon 1']);

        $this->expectException(QueryException::class);

        InspectionSchedule::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'asset_id' => $boat->id,
            'pool_id' => $shop['pool']->id,
            'name' => 'Both',
            'interval_days' => 30,
        ]);
    }

    private function finishedRental(Tenant $tenant, Location $location, Service $service, Pool $pool, BookingStatus $status, string $endsAt): Booking
    {
        $end = CarbonImmutable::parse($endsAt, 'UTC');
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $end->subHour(),
            'ends_at' => $end,
            'participants' => 1,
            'payload_hash' => str_repeat('b', 64),
        ]);
        Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'pool_id' => $pool->id,
            'quantity' => 1,
            'starts_at' => $booking->starts_at,
            'ends_at' => $end,
        ]);

        return $booking;
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
     * @return array{snapshots: int, payments: int, refunds: int, fees: int, fee_refunds: int}
     */
    private function moneyState(): array
    {
        return [
            'snapshots' => PricingSnapshot::query()->count(),
            'payments' => Payment::query()->count(),
            'refunds' => Refund::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'fee_refunds' => PlatformFeeRefund::query()->count(),
        ];
    }
}
