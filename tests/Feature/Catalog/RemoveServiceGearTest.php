<?php

namespace Tests\Feature\Catalog;

use App\Enums\BookingStatus;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Models\Allocation;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class RemoveServiceGearTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_removes_linked_gear_and_the_next_hold_skips_it(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        $jackets = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Life jacket',
            'quantity' => 10,
        ]);
        $jacketLink = ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $shop['service']->id,
            'resource_type' => 'pool',
            'resource_id' => $jackets->id,
            'quantity' => 2,
            'per_participants' => null,
        ]);
        $kayakLink = ResourceRequirement::query()
            ->where('service_id', $shop['service']->id)
            ->where('resource_id', $shop['pool']->id)
            ->firstOrFail();

        Sanctum::actingAs($workspace['user']);
        $bookingId = $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings'), [
            'service_id' => $shop['service']->id,
            'starts_at' => '2026-06-16T15:00:00Z',
            'ends_at' => '2026-06-16T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'both-gear',
        ])->assertCreated()->json('data.id');
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        $this->assertSame($this->sortedIds([$jackets->id, $shop['pool']->id]), $this->allocatedPools($bookingId));

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/services/'.$shop['service']->id))
            ->assertOk()
            ->assertSee('data-gear="'.$jacketLink->id.'"', false)
            ->assertSee('removeRequirement', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.service-detail .row .button { min-height: 44px; white-space: normal; }', false);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->call('removeRequirement', $jacketLink->id)
            ->assertHasNoErrors()
            ->assertSee('Gear removed. Bookings already held stay as they are.')
            ->assertSee('Single kayak')
            ->assertDontSee('Life jacket')
            ->assertSee('removeRequirement', false);

        $this->assertNull(ResourceRequirement::query()->find($jacketLink->id));
        $this->assertNotNull(ResourceRequirement::query()->find($kayakLink->id));
        $this->assertSame(1, AuditLog::query()->where('action', 'service.requirement_removed')->where('subject_id', $jacketLink->id)->count());
        $this->assertSame(10, $jackets->refresh()->quantity);
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($bookingId)->status);
        $this->assertSame($this->sortedIds([$jackets->id, $shop['pool']->id]), $this->allocatedPools($bookingId));

        Sanctum::actingAs($workspace['user']);
        $nextId = $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings'), [
            'service_id' => $shop['service']->id,
            'starts_at' => '2026-06-17T15:00:00Z',
            'ends_at' => '2026-06-17T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'kayak-only',
        ])->assertCreated()->json('data.id');

        $this->assertSame([$shop['pool']->id], Hold::query()->where('booking_id', $nextId)->pluck('pool_id')->all());
        $this->assertSame(0, Hold::query()->where('booking_id', $nextId)->where('pool_id', $jackets->id)->count());

        $this->deleteJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$shop['service']->id.'/requirements/'.$kayakLink->id))
            ->assertOk()
            ->assertJsonPath('message', 'Removed.');

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings'), [
            'service_id' => $shop['service']->id,
            'starts_at' => '2026-06-18T15:00:00Z',
            'ends_at' => '2026-06-18T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'no-gear',
        ])->assertStatus(409)->assertJsonPath('message', 'This service has no resources.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, Booking::query()->count());
        $this->assertSame(BookingStatus::Confirmed, Booking::query()->findOrFail($bookingId)->status);
        $this->assertSame($this->sortedIds([$jackets->id, $shop['pool']->id]), $this->allocatedPools($bookingId));
        $this->assertSame(2, PriceRule::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, PlatformFeeRefund::query()->count());
    }

    public function test_staff_another_workspace_and_the_wrong_service_cannot_remove_gear(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        $link = ResourceRequirement::query()->where('service_id', $shop['service']->id)->firstOrFail();
        $other = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Paddle rental',
            'kind' => 'rental',
        ]);
        $otherLink = ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $other->id,
            'resource_type' => 'pool',
            'resource_id' => $shop['pool']->id,
            'quantity' => 1,
        ]);
        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);
        $this->actingAs($staff);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->assertSee('Single kayak')
            ->assertDontSee('removeRequirement')
            ->call('removeRequirement', $link->id)
            ->assertForbidden();

        Sanctum::actingAs($staff);
        $this->deleteJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$shop['service']->id.'/requirements/'.$link->id))
            ->assertForbidden();
        $this->assertNotNull(ResourceRequirement::query()->find($link->id));

        $this->actingAs($workspace['user']);
        $elsewhere = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South dock',
        ]);
        $workspace['membership']->locations_restricted = true;
        $workspace['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'membership_id' => $workspace['membership']->id,
            'location_id' => $elsewhere->id,
        ]);
        app(StaffLocationAccess::class)->forget();

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->assertNotFound();

        Sanctum::actingAs($workspace['user']);
        $this->deleteJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$shop['service']->id.'/requirements/'.$link->id))
            ->assertNotFound();
        $this->assertNotNull(ResourceRequirement::query()->find($link->id));

        $workspace['membership']->locations_restricted = false;
        $workspace['membership']->save();
        app(StaffLocationAccess::class)->forget();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->call('removeRequirement', $otherLink->id)
            ->assertNotFound();
        $this->assertNotNull(ResourceRequirement::query()->find($otherLink->id));
        $this->assertNotNull(ResourceRequirement::query()->find($link->id));

        Sanctum::actingAs($workspace['user']);
        $this->deleteJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$shop['service']->id.'/requirements/'.$otherLink->id))
            ->assertNotFound();
        $this->assertNotNull(ResourceRequirement::query()->find($otherLink->id));

        $beta = $this->workspace('beta.test');
        Sanctum::actingAs($beta['user']);
        $this->deleteJson($this->tenantUrl('alpha.test', '/api/v1/services/'.$shop['service']->id.'/requirements/'.$link->id))
            ->assertForbidden();
        $this->deleteJson($this->tenantUrl('beta.test', '/api/v1/services/'.$shop['service']->id.'/requirements/'.$link->id))
            ->assertNotFound();

        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);
        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->assertNotFound();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertNotNull(ResourceRequirement::query()->find($link->id));
        $this->assertSame(0, AuditLog::query()->where('action', 'service.requirement_removed')->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, PlatformFeeRefund::query()->count());
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function sortedIds(array $ids): array
    {
        sort($ids);

        return array_values($ids);
    }

    /**
     * @return list<string>
     */
    private function allocatedPools(string $bookingId): array
    {
        $ids = [];

        foreach (Allocation::query()->where('booking_id', $bookingId)->pluck('pool_id') as $id) {
            if (is_string($id)) {
                $ids[] = $id;
            }
        }

        return $this->sortedIds($ids);
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(): array
    {
        $workspace = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }
}
