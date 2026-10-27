<?php

namespace Tests\Feature\Web;

use App\Enums\MaintenanceKind;
use App\Livewire\Staff\Maintenance;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\InspectionSchedule;
use App\Models\Location;
use App\Models\MaintenanceRecord;
use App\Models\MaintenanceWindow;
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
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Operations\MaintenancePlanner;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffMaintenanceTest extends TestCase
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

    public function test_a_window_makes_the_resource_unavailable_in_the_public_availability_check(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $inside = ['starts_at' => '2026-07-04T14:00:00Z', 'ends_at' => '2026-07-04T15:00:00Z'];
        $after = ['starts_at' => '2026-07-04T16:00:00Z', 'ends_at' => '2026-07-04T17:00:00Z'];

        $this->actingAsWorkspace($alpha);
        $this->assertTrue($this->available($shop['service']->id, $inside));

        $this->scheduleAs($alpha, 'pool:'.$shop['pool']->id, '2026-07-04T10:00', '2026-07-04T12:00', 'Hull repair')
            ->assertHasNoErrors()
            ->assertSee('Maintenance scheduled. No future bookings are affected.');

        app(TenantManager::class)->set($alpha['tenant']);
        $window = MaintenanceWindow::query()->sole();
        $this->assertSame('2026-07-04 14:00:00', $window->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-04 16:00:00', $window->ends_at->utc()->format('Y-m-d H:i:s'));
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($alpha);
        $this->assertFalse($this->available($shop['service']->id, $inside));
        $this->assertTrue($this->available($shop['service']->id, $after));

        app(TenantManager::class)->set($alpha['tenant']);

        try {
            app(Allocator::class)->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-04 15:00:00', 'UTC'), 'inside-window'));
            $this->fail('A hold was placed inside a maintenance window.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That resource is out of service for maintenance at that time.', $exception->getMessage());
        }

        $token = app(WidgetTokens::class)->hostedPlain($alpha['tenant']);
        $duration = $shop['service']->durations->first();
        $this->assertNotNull($duration);
        app(TenantManager::class)->forget();

        $this->post($this->tenantUrl('alpha.test', '/book/'.$token.'/services/'.$shop['service']->id), [
            'date' => '2026-07-04',
            'start' => '10:00',
            'duration_id' => $duration->id,
            'participants' => 1,
            'customer_name' => 'Public Guest',
            'customer_email' => 'public@example.com',
            'idempotency_key' => 'public-in-window',
        ])->assertStatus(409)->assertSee('That resource is out of service for maintenance at that time.');

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertNull(Booking::query()->where('idempotency_key', 'public-in-window')->first());
    }

    public function test_overlapping_future_bookings_are_flagged_and_not_cancelled(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $allocator = app(Allocator::class);
        $confirmed = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-04 14:30:00', 'UTC'), 'confirmed', 'Ada Lovelace')));
        $held = $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-04 15:00:00', 'UTC'), 'held', 'Held Guest'));
        $edge = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-04 16:00:00', 'UTC'), 'edge', 'Edge Guest')));
        $allocationsBefore = Allocation::query()->count();

        $this->scheduleAs($alpha, 'pool:'.$shop['pool']->id, '2026-07-04T10:00', '2026-07-04T12:00', 'Hull repair')
            ->assertHasNoErrors()
            ->assertSee('2 future bookings are flagged for follow-up. Nothing was cancelled or moved.')
            ->assertSeeHtml('data-affected-booking="'.$confirmed->id.'"')
            ->assertSeeHtml('data-affected-booking="'.$held->id.'"')
            ->assertDontSeeHtml('data-affected-booking="'.$edge->id.'"')
            ->assertSee('Ada Lovelace')
            ->assertSee('Flagged');

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('confirmed', $confirmed->fresh()->status->value);
        $this->assertSame('Hull repair', $confirmed->fresh()->attention_reason);
        $this->assertSame('held', $held->fresh()->status->value);
        $this->assertSame('Hull repair', $held->fresh()->attention_reason);
        $this->assertNull($edge->fresh()->attention_reason);
        $this->assertSame($allocationsBefore, Allocation::query()->count());
        $this->assertSame(1, Allocation::query()->where('booking_id', $confirmed->id)->count());

        $this->assertSame('confirmed', $allocator->confirm($held->fresh())->status->value);
        $this->assertSame('Hull repair', $held->fresh()->attention_reason);
    }

    public function test_marking_an_inspection_done_schedules_the_next_one(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $boat = Asset::factory()->create(['location_id' => $shop['location']->id, 'name' => 'Pontoon 1']);
        $this->actingAs($alpha['user']);

        Livewire::test(Maintenance::class)
            ->set('inspectionResource', 'asset:'.$boat->id)
            ->set('inspectionName', 'Safety check')
            ->set('inspectionEvery', '30')
            ->set('inspectionUnit', 'days')
            ->call('addInspection')
            ->assertHasNoErrors()
            ->set('inspectionResource', 'asset:'.$boat->id)
            ->set('inspectionName', 'Prop check')
            ->set('inspectionEvery', '5')
            ->set('inspectionUnit', 'uses')
            ->call('addInspection')
            ->assertHasNoErrors();

        $byDays = InspectionSchedule::query()->where('name', 'Safety check')->sole();
        $byUses = InspectionSchedule::query()->where('name', 'Prop check')->sole();
        $this->assertSame('2026-07-31 15:00:00', $byDays->next_due_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(5, $byUses->interval_uses);
        $this->assertNull($byUses->next_due_at);

        Carbon::setTestNow(Carbon::parse('2026-08-02 13:00:00', 'UTC'));
        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(Maintenance::class)
            ->assertSee('Due')
            ->set('doneNote.'.$byDays->id, 'Hull fine')
            ->call('markDone', $byDays->id)
            ->assertHasNoErrors()
            ->assertSee('Safety check done. Next due Sep 1, 2026.');

        $byDays->refresh();
        $this->assertSame('2026-08-02 13:00:00', $byDays->last_done_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-01 13:00:00', $byDays->next_due_at?->utc()->format('Y-m-d H:i:s'));
        $record = MaintenanceRecord::query()->sole();
        $this->assertSame($boat->id, $record->asset_id);
        $this->assertSame('Safety check: Hull fine', $record->summary);
        $this->assertSame('2026-08-02 13:00:00', $record->inspected_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_another_workspace_or_a_location_the_member_cannot_use_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $north = $this->rentalShop($alpha['tenant']);
        $south = Location::factory()->create(['tenant_id' => $alpha['tenant']->id, 'name' => 'South dock', 'timezone' => 'America/New_York']);
        $southBoat = Asset::factory()->create(['location_id' => $south->id, 'name' => 'South boat']);
        $southPool = Pool::factory()->create(['tenant_id' => $alpha['tenant']->id, 'location_id' => $south->id, 'name' => 'South kayaks']);
        $southCheck = InspectionSchedule::factory()->create(['asset_id' => $southBoat->id, 'name' => 'South check']);
        MaintenanceWindow::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'pool_id' => $southPool->id,
            'starts_at' => '2026-07-10 14:00:00',
            'ends_at' => '2026-07-10 16:00:00',
            'reason' => 'South secret repair',
        ]);
        MaintenanceWindow::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'pool_id' => $north['pool']->id,
            'starts_at' => '2026-07-10 14:00:00',
            'ends_at' => '2026-07-10 16:00:00',
            'reason' => 'North oil change',
        ]);
        app(TenantManager::class)->forget();

        $beta = $this->workspace('beta.test');
        $this->actingAs($beta['user'])
            ->get($this->tenantUrl('beta.test', '/staff/maintenance'))
            ->assertOk()
            ->assertDontSee('North oil change')
            ->assertDontSee('South secret repair');

        app(TenantManager::class)->set($beta['tenant']);
        Livewire::test(Maintenance::class)
            ->set('windowResource', 'pool:'.$north['pool']->id)
            ->set('windowReason', 'Takeover')
            ->call('scheduleWindow')
            ->assertNotFound();
        Livewire::test(Maintenance::class)->call('markDone', $southCheck->id)->assertNotFound();

        $manager = $this->member($alpha['tenant'], 'manager');
        $this->limit($manager['membership'], $north['location']->id);
        $this->actingAs($manager['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/maintenance'))
            ->assertOk()
            ->assertSee('North oil change')
            ->assertDontSee('South secret repair')
            ->assertDontSee('South check');
        $this->get($this->tenantUrl('alpha.test', '/staff/maintenance?location='.$south->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/maintenance?resource=asset:'.$southBoat->id))->assertNotFound();

        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Maintenance::class)
            ->set('windowResource', 'asset:'.$southBoat->id)
            ->set('windowReason', 'Sneaky')
            ->call('scheduleWindow')
            ->assertNotFound();
        Livewire::test(Maintenance::class)->call('markDone', $southCheck->id)->assertNotFound();
        Livewire::test(Maintenance::class)
            ->set('inspectionResource', 'asset:'.$southBoat->id)
            ->set('inspectionName', 'Sneaky check')
            ->call('addInspection')
            ->assertNotFound();

        $staff = $this->member($alpha['tenant'], 'staff');
        $this->actingAs($staff['user']);
        app(StaffLocationAccess::class)->forget();
        Livewire::test(Maintenance::class)
            ->assertDontSee('Take out of service')
            ->set('windowResource', 'pool:'.$north['pool']->id)
            ->set('windowReason', 'Not allowed')
            ->call('scheduleWindow')
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
        $this->assertSame(2, MaintenanceWindow::query()->count());
        $this->assertSame(1, InspectionSchedule::query()->count());
        $this->assertSame(0, MaintenanceRecord::query()->count());
    }

    public function test_ending_a_window_early_frees_later_bookings_and_keeps_one_that_still_overlaps(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $shop['pool']->update(['prep_buffer_minutes' => 60]);
        $allocator = app(Allocator::class);
        $planner = app(MaintenancePlanner::class);
        $soon = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-01 15:30:00', 'UTC'), 'soon', 'Soon Guest')));
        $tail = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-02 15:00:00', 'UTC'), 'tail', 'Tail Guest')));
        $kept = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-04 15:00:00', 'UTC'), 'kept', 'Kept Guest')));
        $money = $this->moneyState();
        $window = $planner->schedule(
            $shop['pool']->fresh(),
            MaintenanceKind::Maintenance,
            CarbonImmutable::parse('2026-07-01 14:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-04 18:00:00', 'UTC'),
            'Hull repair',
        )['window'];
        $planner->schedule(
            $shop['pool']->fresh(),
            MaintenanceKind::Maintenance,
            CarbonImmutable::parse('2026-07-04 14:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-04 18:00:00', 'UTC'),
            'Hull repair',
        );

        $this->assertSame('Hull repair', $soon->fresh()->attention_reason);
        $this->assertSame('Hull repair', $tail->fresh()->attention_reason);
        $this->assertSame('Hull repair', $kept->fresh()->attention_reason);

        $this->actingAsWorkspace($alpha);
        $inside = ['starts_at' => '2026-07-02T15:00:00Z', 'ends_at' => '2026-07-02T16:00:00Z'];
        $this->assertFalse($this->available($shop['service']->id, $inside));

        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);
        Livewire::test(Maintenance::class)
            ->assertSee('End now')
            ->call('endWindow', $window->id)
            ->assertHasNoErrors()
            ->assertSee('1 future booking is no longer flagged for this, and nothing was cancelled or moved.')
            ->assertSee('Ended Wed, Jul 1, 11:00 AM');

        $soon->refresh();
        $tail->refresh();
        $kept->refresh();
        $window->refresh();
        $this->assertSame('confirmed', $soon->status->value);
        $this->assertSame('Hull repair', $soon->attention_reason);
        $this->assertSame('confirmed', $tail->status->value);
        $this->assertNull($tail->attention_reason);
        $this->assertSame('confirmed', $kept->status->value);
        $this->assertSame('Hull repair', $kept->attention_reason);
        $this->assertSame('2026-07-01 15:00:00', $window->ended_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-04 18:00:00', $window->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertTrue(AuditLog::query()->where('action', 'maintenance.ended')->where('subject_id', $window->id)->exists());
        $this->assertSame($money, $this->moneyState());

        try {
            $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-01 15:30:00', 'UTC'), 'still-blocked'));
            $this->fail('A hold was placed on the part of the window that already started.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That resource is out of service for maintenance at that time.', $exception->getMessage());
        }

        $freed = $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, CarbonImmutable::parse('2026-07-03 17:00:00', 'UTC'), 'freed'));
        $this->assertSame('held', $freed->status->value);

        $this->actingAsWorkspace($alpha);
        $this->assertTrue($this->available($shop['service']->id, $inside));

        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);
        Livewire::test(Maintenance::class)
            ->call('endWindow', $window->id)
            ->assertSee('That window has already ended.');
        $this->assertSame('2026-07-01 15:00:00', $window->fresh()->ended_at?->utc()->format('Y-m-d H:i:s'));

        $block = $planner->schedule(
            $shop['pool']->fresh(),
            MaintenanceKind::Block,
            CarbonImmutable::parse('2026-07-20 14:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-20 18:00:00', 'UTC'),
            'Private event',
        )['window'];
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($alpha);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/maintenance-windows/'.$block->id.'/end'))
            ->assertOk()
            ->assertJsonPath('data.cleared_bookings', 0)
            ->assertJsonPath('data.id', $block->id);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/maintenance-windows/'.$block->id.'/end'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('window');

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('2026-07-01 15:00:00', $block->fresh()->ended_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(2, AuditLog::query()->where('action', 'maintenance.ended')->count());
        $this->assertSame($money, $this->moneyState());
    }

    public function test_ending_is_refused_for_another_workspace_a_limited_member_and_view_only_staff(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $north = $this->rentalShop($alpha['tenant']);
        $south = Location::factory()->create(['tenant_id' => $alpha['tenant']->id, 'name' => 'South dock', 'timezone' => 'America/New_York']);
        $southPool = Pool::factory()->create(['tenant_id' => $alpha['tenant']->id, 'location_id' => $south->id, 'name' => 'South kayaks']);
        $live = MaintenanceWindow::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'pool_id' => $north['pool']->id,
            'starts_at' => '2026-07-01 14:00:00',
            'ends_at' => '2026-07-01 18:00:00',
            'reason' => 'North oil change',
        ]);
        $southWindow = MaintenanceWindow::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'pool_id' => $southPool->id,
            'starts_at' => '2026-07-10 14:00:00',
            'ends_at' => '2026-07-10 16:00:00',
            'reason' => 'South secret repair',
        ]);
        $past = MaintenanceWindow::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'pool_id' => $north['pool']->id,
            'starts_at' => '2026-07-01 12:00:00',
            'ends_at' => '2026-07-01 14:00:00',
            'reason' => 'Already finished',
        ]);
        app(TenantManager::class)->forget();

        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);
        Livewire::test(Maintenance::class)->call('endWindow', $live->id)->assertNotFound();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertNull($live->fresh()->ended_at);

        $manager = $this->member($alpha['tenant'], 'manager');
        $this->limit($manager['membership'], $north['location']->id);
        $this->actingAs($manager['user']);
        Livewire::test(Maintenance::class)->call('endWindow', $southWindow->id)->assertNotFound();
        $this->assertNull($southWindow->fresh()->ended_at);

        $staff = $this->member($alpha['tenant'], 'staff');
        $this->actingAs($staff['user']);
        app(StaffLocationAccess::class)->forget();
        Livewire::test(Maintenance::class)
            ->assertDontSee('End now')
            ->call('endWindow', $live->id)
            ->assertForbidden();
        $this->assertNull($live->fresh()->ended_at);

        $this->actingAs($alpha['user']);
        app(StaffLocationAccess::class)->forget();
        Livewire::test(Maintenance::class)
            ->call('endWindow', $past->id)
            ->assertSee('That window has already ended.');
        $this->assertNull($past->fresh()->ended_at);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function scheduleAs(array $workspace, string $resourceKey, string $from, string $until, string $reason): mixed
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return Livewire::test(Maintenance::class)
            ->set('windowResource', $resourceKey)
            ->set('windowKind', 'maintenance')
            ->set('windowStarts', $from)
            ->set('windowEnds', $until)
            ->set('windowReason', $reason)
            ->call('scheduleWindow');
    }

    /**
     * @param  array{starts_at: string, ends_at: string}  $interval
     */
    private function available(string $serviceId, array $interval): bool
    {
        app(TenantManager::class)->forget();

        return (bool) $this->getJson($this->tenantUrl('alpha.test', '/api/v1/availability?'.http_build_query(['service_id' => $serviceId] + $interval)))
            ->assertOk()
            ->assertJsonPath('data.capacity_checked', true)
            ->json('data.available');
    }

    private function intent(string $tenantId, string $serviceId, CarbonImmutable $start, string $key, string $name = 'Guest'): AllocationIntent
    {
        return new AllocationIntent($tenantId, $serviceId, $start, $start->addHour(), 1, $key, $name, 'guest@example.com');
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

    /**
     * @return array{snapshots: list<array<string, mixed>>, payments: int, refunds: int, fees: int, fee_refunds: int}
     */
    private function moneyState(): array
    {
        return [
            'snapshots' => PricingSnapshot::query()->orderBy('id')->get()->map(fn (PricingSnapshot $row): array => $row->payload ?? [])->all(),
            'payments' => Payment::query()->count(),
            'refunds' => Refund::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'fee_refunds' => PlatformFeeRefund::query()->count(),
        ];
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
}
