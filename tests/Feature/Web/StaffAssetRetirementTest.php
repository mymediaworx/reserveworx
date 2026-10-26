<?php

namespace Tests\Feature\Web;

use App\Enums\ResourceStatus;
use App\Livewire\Staff\Setup\Resources;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\StaffLocationScope;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffAssetRetirementTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_retires_an_asset_and_moves_a_future_booking_onto_the_replacement(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $asset, $replacement] = $this->pair();
        $this->actingAsWorkspace($workspace);
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
        $this->signedIn($workspace);
        $money = $this->money($future);

        Livewire::test(Resources::class)
            ->assertSee('openRetire')
            ->assertSee('@media (max-width: 390px)', false)
            ->call('setStatus', 'asset', $asset->id, 'retired')
            ->assertHasErrors('asset-'.$asset->id)
            ->call('openRetire', $asset->id)
            ->assertSee('Replacement for '.$asset->name)
            ->set('replacementId', $replacement->id)
            ->call('retire')
            ->assertHasNoErrors()
            ->assertSee($asset->name.' is retired. Future bookings stay booked.')
            ->assertSeeHtml('data-retire-offer="'.$future.'"')
            ->assertDontSeeHtml('data-retire-offer="'.$past.'"')
            ->assertDontSeeHtml('data-retire-offer="'.$held.'"')
            ->call('acceptOffer', $future, $asset->id, $replacement->id)
            ->assertHasNoErrors()
            ->assertSee('Moved onto the replacement. The price stays as it was.')
            ->assertDontSeeHtml('data-retire-offer="'.$future.'"');

        app(TenantManager::class)->set($workspace['tenant']);
        $retired = Asset::query()->findOrFail($asset->id);
        $this->assertSame(ResourceStatus::Retired, $retired->status);
        $this->assertSame($replacement->id, $retired->replacement_asset_id);
        $this->assertSame('Asset is retired.', Booking::query()->findOrFail($future)->attention_reason);
        $this->assertNull(Booking::query()->findOrFail($past)->attention_reason);
        $this->assertNull(Booking::query()->findOrFail($held)->attention_reason);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $past)->value('asset_id'));
        $this->assertSame($replacement->id, Allocation::query()->where('booking_id', $future)->value('asset_id'));
        $this->assertSame('confirmed', Booking::query()->findOrFail($future)->status->value);
        $this->assertTrue(AuditLog::query()->where('action', 'asset.retired')->where('subject_id', $asset->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'booking.reassigned')->where('subject_id', $future)->exists());
        $this->assertSame($money, $this->money($future));
        app(TenantManager::class)->forget();
    }

    public function test_a_taken_replacement_leaves_the_booking_where_it_was(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $asset, $replacement, $location] = $this->pair();
        $this->actingAsWorkspace($workspace);
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

        $this->signedIn($workspace);
        $money = $this->money($blocked);

        Livewire::test(Resources::class)
            ->call('openRetire', $asset->id)
            ->set('replacementId', $replacement->id)
            ->call('retire')
            ->call('acceptOffer', $blocked, $asset->id, $replacement->id)
            ->assertHasErrors('move-'.$blocked)
            ->assertSeeHtml('data-retire-offer="'.$blocked.'"');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $blocked)->value('asset_id'));
        $this->assertSame('confirmed', Booking::query()->findOrFail($blocked)->status->value);
        $this->assertSame($money, $this->money($blocked));
        app(TenantManager::class)->forget();
    }

    public function test_a_pool_cannot_be_retired_and_a_bad_replacement_is_refused(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service, $asset, $replacement, $location, $category] = $this->pair();
        $this->actingAsWorkspace($workspace);
        $booking = $this->confirmed($service, '2026-06-17T15:00:00Z', '2026-06-17T16:00:00Z', 'stay-put');

        app(TenantManager::class)->set($workspace['tenant']);
        $pool = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayaks',
            'quantity' => 4,
        ]);
        $elsewhere = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $otherPlace = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $elsewhere->id,
            'resource_category_id' => $category->id,
            'name' => 'Boat C',
        ]);
        app(TenantManager::class)->forget();

        $this->signedIn($workspace);

        Livewire::test(Resources::class)
            ->assertDontSeeHtml('openRetire(\''.$pool->id.'\')')
            ->call('setStatus', 'pool', $pool->id, 'retired')
            ->assertHasErrors('pool-'.$pool->id)
            ->call('openRetire', $asset->id)
            ->set('replacementId', $otherPlace->id)
            ->call('retire')
            ->assertHasErrors('replacement_asset_id')
            ->set('replacementId', $asset->id)
            ->call('retire')
            ->assertHasErrors('replacement_asset_id');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(ResourceStatus::Active, $pool->refresh()->status);
        $this->assertSame(ResourceStatus::Active, Asset::query()->findOrFail($asset->id)->status);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $booking)->value('asset_id'));
        app(TenantManager::class)->forget();
    }

    public function test_staff_and_another_workspace_cannot_retire_the_asset(): void
    {
        $staff = $this->workspace('dock.test', 'staff');
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($staff['tenant']);
        $location = Location::factory()->create(['tenant_id' => $staff['tenant']->id, 'timezone' => 'UTC']);
        $asset = Asset::factory()->create([
            'tenant_id' => $staff['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Staff boat',
        ]);
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($beta['tenant']);
        $betaAsset = Asset::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $beta['tenant']->id])->id,
            'name' => 'Beta boat',
        ]);
        app(TenantManager::class)->forget();

        $this->signedIn($staff);
        Livewire::test(Resources::class)
            ->assertDontSee('openRetire')
            ->call('openRetire', $asset->id)
            ->assertForbidden();

        $this->signedIn($staff);
        Livewire::test(Resources::class)
            ->call('retire')
            ->assertNotFound();

        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);
        Livewire::test(Resources::class)
            ->call('openRetire', $asset->id)
            ->assertNotFound();

        app(TenantManager::class)->set($staff['tenant']);
        $this->assertSame(ResourceStatus::Active, Asset::query()->findOrFail($asset->id)->status);
        app(TenantManager::class)->forget();
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(ResourceStatus::Active, Asset::query()->findOrFail($betaAsset->id)->status);
        app(TenantManager::class)->forget();
    }

    public function test_a_member_limited_to_another_location_does_not_see_the_asset(): void
    {
        $workspace = $this->workspace('dock.test', 'manager');
        app(TenantManager::class)->set($workspace['tenant']);
        $north = Location::factory()->create(['tenant_id' => $workspace['tenant']->id, 'name' => 'North', 'timezone' => 'UTC']);
        $south = Location::factory()->create(['tenant_id' => $workspace['tenant']->id, 'name' => 'South', 'timezone' => 'UTC']);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $north->id,
            'name' => 'North boat',
        ]);
        $workspace['membership']->locations_restricted = true;
        $workspace['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'membership_id' => $workspace['membership']->id,
            'location_id' => $south->id,
        ]);
        app(StaffLocationAccess::class)->forget();
        $this->actingAs($workspace['user']);

        Livewire::test(Resources::class)
            ->assertDontSee('North boat')
            ->call('openRetire', $asset->id)
            ->assertNotFound();

        $this->assertSame(
            ResourceStatus::Active,
            Asset::query()->withoutGlobalScope(StaffLocationScope::class)->findOrFail($asset->id)->status,
        );
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function signedIn(array $workspace): void
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
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
     * @return array{snapshots: list<array<string, mixed>|null>, payments: int, refunds: int, fees: int, fee_refunds: int}
     */
    private function money(string $bookingId): array
    {
        return [
            'snapshots' => PricingSnapshot::query()->where('booking_id', $bookingId)->orderBy('id')->pluck('payload')->all(),
            'payments' => Payment::query()->where('booking_id', $bookingId)->count(),
            'refunds' => Refund::query()->where('booking_id', $bookingId)->count(),
            'fees' => PlatformFee::query()->where('booking_id', $bookingId)->count(),
            'fee_refunds' => PlatformFeeRefund::query()->whereHas('platformFee', function ($query) use ($bookingId): void {
                $query->where('booking_id', $bookingId);
            })->count(),
        ];
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
