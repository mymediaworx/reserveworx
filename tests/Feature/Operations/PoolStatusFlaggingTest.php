<?php

namespace Tests\Feature\Operations;

use App\Enums\BookingStatus;
use App\Enums\ResourceStatus;
use App\Livewire\Staff\Setup\Resources;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PoolStatusFlaggingTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_marking_a_pool_unavailable_flags_future_bookings_and_leaves_them_booked(): void
    {
        $workspace = $this->workspace('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $other = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Tandem kayak',
            'quantity' => 2,
        ]);
        $otherService = $this->serviceOn($workspace['tenant'], $shop['location'], $other, 'Tandem rental');
        $allocator = app(Allocator::class);
        $future = $this->book($allocator, $workspace['tenant']->id, $shop['service']->id, '2026-06-17 15:00:00', 'future-pool');
        $held = $this->book($allocator, $workspace['tenant']->id, $shop['service']->id, '2026-06-18 15:00:00', 'held-pool', false);
        $started = $this->book($allocator, $workspace['tenant']->id, $shop['service']->id, '2026-06-16 15:00:00', 'started-pool');
        $started->starts_at = CarbonImmutable::parse('2026-06-16 11:00:00', 'UTC');
        $started->save();
        $cancelled = $this->book($allocator, $workspace['tenant']->id, $shop['service']->id, '2026-06-19 15:00:00', 'cancelled-pool');
        $cancelled->status = BookingStatus::Cancelled;
        $cancelled->save();
        $elsewhere = $this->book($allocator, $workspace['tenant']->id, $otherService->id, '2026-06-17 15:00:00', 'other-pool');
        $allocations = Allocation::query()->count();
        $money = $this->money();
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id), [
            'quantity' => 3,
        ])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.quantity', 3);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertNull($future->fresh()->attention_reason);
        $this->assertSame(3, $shop['pool']->fresh()->quantity);
        app(TenantManager::class)->forget();

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id), [
            'status' => 'maintenance',
        ])->assertOk()->assertJsonPath('data.status', 'maintenance');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('confirmed', $future->fresh()->status->value);
        $this->assertSame('Pool is maintenance.', $future->fresh()->attention_reason);
        $this->assertSame('held', $held->fresh()->status->value);
        $this->assertSame('Pool is maintenance.', $held->fresh()->attention_reason);
        $this->assertSame('confirmed', $started->fresh()->status->value);
        $this->assertNull($started->fresh()->attention_reason);
        $this->assertSame('cancelled', $cancelled->fresh()->status->value);
        $this->assertNull($cancelled->fresh()->attention_reason);
        $this->assertSame('confirmed', $elsewhere->fresh()->status->value);
        $this->assertNull($elsewhere->fresh()->attention_reason);
        $this->assertSame($shop['pool']->id, Allocation::query()->where('booking_id', $future->id)->value('pool_id'));
        $this->assertSame($other->id, Allocation::query()->where('booking_id', $elsewhere->id)->value('pool_id'));
        $this->assertSame($allocations, Allocation::query()->count());
        $this->assertSame($money, $this->money());
        app(TenantManager::class)->forget();

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id), [
            'status' => 'active',
        ])->assertOk()->assertJsonPath('data.status', 'active');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('Pool is maintenance.', $future->fresh()->attention_reason);
        $this->assertSame('confirmed', $future->fresh()->status->value);
        app(TenantManager::class)->forget();

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id), [
            'status' => 'blocked',
        ])->assertOk()->assertJsonPath('data.status', 'blocked');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(ResourceStatus::Blocked, $shop['pool']->fresh()->status);
        $this->assertSame('Pool is blocked.', $future->fresh()->attention_reason);
        $this->assertSame('Pool is blocked.', $held->fresh()->attention_reason);
        $this->assertNull($started->fresh()->attention_reason);
        $this->assertNull($elsewhere->fresh()->attention_reason);
        $this->assertSame('confirmed', $future->fresh()->status->value);
        $this->assertSame($money, $this->money());
        app(TenantManager::class)->forget();
    }

    public function test_the_equipment_page_flags_a_pool_and_a_boat_and_a_count_change_does_not(): void
    {
        $workspace = $this->workspace('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Pontoon',
        ]);
        $boat = $this->serviceOn($workspace['tenant'], $shop['location'], $asset, 'Pontoon rental');
        $allocator = app(Allocator::class);
        $poolBooking = $this->book($allocator, $workspace['tenant']->id, $shop['service']->id, '2026-06-17 15:00:00', 'page-pool');
        $boatBooking = $this->book($allocator, $workspace['tenant']->id, $boat->id, '2026-06-17 16:00:00', 'page-boat');
        $money = $this->money();
        $this->actingAs($workspace['user']);

        Livewire::test(Resources::class)
            ->call('setQuantity', $shop['pool']->id, 2)
            ->assertHasNoErrors();

        $this->assertSame(2, $shop['pool']->fresh()->quantity);
        $this->assertNull($poolBooking->fresh()->attention_reason);
        $this->assertNull($boatBooking->fresh()->attention_reason);

        Livewire::test(Resources::class)
            ->call('setStatus', 'pool', $shop['pool']->id, 'maintenance')
            ->assertHasNoErrors()
            ->call('setStatus', 'asset', $asset->id, 'blocked')
            ->assertHasNoErrors();

        $this->assertSame(2, $shop['pool']->fresh()->quantity);
        $this->assertSame(ResourceStatus::Maintenance, $shop['pool']->fresh()->status);
        $this->assertSame(ResourceStatus::Blocked, $asset->fresh()->status);
        $this->assertSame('confirmed', $poolBooking->fresh()->status->value);
        $this->assertSame('Pool is maintenance.', $poolBooking->fresh()->attention_reason);
        $this->assertSame('confirmed', $boatBooking->fresh()->status->value);
        $this->assertSame('Asset is blocked.', $boatBooking->fresh()->attention_reason);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $boatBooking->id)->value('asset_id'));
        $this->assertSame($money, $this->money());
    }

    public function test_staff_another_location_and_another_workspace_cannot_flag_the_pool(): void
    {
        $workspace = $this->workspace('dock.test', 'manager');
        $staffUser = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staffUser->id,
        ]);
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $south = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South dock',
            'timezone' => 'UTC',
        ]);
        $allocator = app(Allocator::class);
        $future = $this->book($allocator, $workspace['tenant']->id, $shop['service']->id, '2026-06-17 15:00:00', 'locked-pool');
        $this->limit($workspace['membership'], $south->id);
        app(TenantManager::class)->set($beta['tenant']);
        $foreign = Pool::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $beta['tenant']->id, 'timezone' => 'UTC'])->id,
            'name' => 'Beta kayaks',
        ]);
        app(TenantManager::class)->forget();

        $this->actingAsWorkspace(['user' => $staffUser]);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id), [
            'status' => 'maintenance',
        ])->assertForbidden();

        $this->actingAsWorkspace($workspace);
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$shop['pool']->id), [
            'status' => 'blocked',
        ])->assertNotFound();
        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/pools/'.$foreign->id), [
            'status' => 'maintenance',
        ])->assertNotFound();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($staffUser);
        Livewire::test(Resources::class)
            ->call('setStatus', 'pool', $shop['pool']->id, 'maintenance')
            ->assertForbidden();

        $this->actingAs($workspace['user']);
        Livewire::test(Resources::class)
            ->call('setStatus', 'pool', $shop['pool']->id, 'blocked')
            ->assertNotFound();

        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);
        app(TenantManager::class)->set($workspace['tenant']);
        Livewire::test(Resources::class)
            ->call('setStatus', 'pool', $foreign->id, 'maintenance')
            ->assertNotFound();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(ResourceStatus::Active, $shop['pool']->fresh()->status);
        $this->assertNull($future->fresh()->attention_reason);
        $this->assertSame('confirmed', $future->fresh()->status->value);
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(ResourceStatus::Active, $foreign->fresh()->status);
    }

    private function book(Allocator $allocator, string $tenantId, string $serviceId, string $start, string $key, bool $confirm = true): Booking
    {
        $starts = CarbonImmutable::parse($start, 'UTC');
        $held = $allocator->hold(new AllocationIntent(
            $tenantId,
            $serviceId,
            $starts,
            $starts->addHour(),
            1,
            $key,
            'Guest',
            $key.'@example.com',
        ));

        return $confirm ? $allocator->confirm($held) : $held;
    }

    private function serviceOn(Tenant $tenant, Location $location, Asset|Pool $resource, string $name): Service
    {
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => $resource instanceof Asset ? 'asset' : 'pool',
            'resource_id' => $resource->id,
            'quantity' => 1,
        ]);

        return $service;
    }

    /**
     * @return array{snapshots: list<array<string, mixed>>, payments: int, refunds: int, fees: int, fee_refunds: int}
     */
    private function money(): array
    {
        return [
            'snapshots' => PricingSnapshot::query()->orderBy('id')->get()->map(fn (PricingSnapshot $row): array => $row->payload ?? [])->all(),
            'payments' => Payment::query()->count(),
            'refunds' => Refund::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'fee_refunds' => PlatformFeeRefund::query()->count(),
        ];
    }

    private function limit(Membership $membership, string $locationId): void
    {
        $membership->locations_restricted = true;
        $membership->save();
        MembershipLocation::query()->create([
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'location_id' => $locationId,
        ]);
        app(StaffLocationAccess::class)->forget();
    }
}
