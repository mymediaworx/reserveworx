<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\BookingShow;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffBookingClockTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_keep_both_fall_back_hours_when_rescheduling_or_extending(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->shop($workspace, 'America/New_York', 'Harbor Kayak');
        $utc = $this->shop($workspace, 'UTC', 'Utc Kayak');
        $fallback = $this->shop($workspace, 'Not/AZone', 'Fallback Kayak');
        $creek = $this->shop($other, 'America/New_York', 'Creek Kayak');

        $this->actingAsWorkspace($workspace);
        $later = $this->confirmed('dock.test', $harbor, '2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z', 'later');
        $extend = $this->confirmed('dock.test', $harbor, '2026-11-01T04:30:00Z', '2026-11-01T05:00:00Z', 'extend');
        $utcBooking = $this->confirmed('dock.test', $utc, '2026-11-01T01:30:00Z', '2026-11-01T02:30:00Z', 'utc');
        $business = $this->confirmed('dock.test', $fallback, '2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z', 'business');
        $this->actingAsWorkspace($other);
        $creekBooking = $this->confirmed('creek.test', $creek, '2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z', 'creek');

        $money = $this->moneyCounts();

        $this->actingAs($workspace['user']);
        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$later))
            ->assertOk()
            ->assertSee('data-start-label="2026-11-01T01:30-05:00"', false)
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('button[data-clock-choice] { width: 100%; white-space: normal; overflow-wrap: anywhere; }', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('Creek Kayak')
            ->assertDontSee('Not/AZone');

        $this->assertSame($money, $this->moneyCounts());

        app(TenantManager::class)->set($workspace['tenant']);

        Livewire::test(BookingShow::class, ['booking' => $later])
            ->assertSet('rescheduleStart', '2026-11-01T01:30-05:00')
            ->set('rescheduleStart', '2026-11-01T01:30')
            ->assertSee('data-start-choice="2026-11-01T01:30-04:00"', false)
            ->assertSee('data-start-choice="2026-11-01T01:30-05:00"', false)
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->call('reschedule')
            ->assertSet('flash', 'Rescheduled.');

        $this->assertSame('2026-11-01 05:30:00', $this->stamp($later, 'starts_at'));
        $this->assertSame('2026-11-01 07:30:00', $this->stamp($later, 'ends_at'));

        Livewire::test(BookingShow::class, ['booking' => $later])
            ->call('useRescheduleStart', '2026-11-01T01:30-05:00')
            ->assertSet('rescheduleStart', '2026-11-01T01:30-05:00')
            ->call('reschedule')
            ->assertSet('flash', 'Rescheduled.');

        $this->assertSame('2026-11-01 06:30:00', $this->stamp($later, 'starts_at'));
        $this->assertSame('2026-11-01 07:30:00', $this->stamp($later, 'ends_at'));

        Livewire::test(BookingShow::class, ['booking' => $later])
            ->set('rescheduleStart', '2026-03-08T02:30')
            ->set('rescheduleEnd', '2026-03-08T03:30')
            ->call('reschedule')
            ->assertHasErrors(['rescheduleStart' => 'That time does not exist in this location.']);

        Livewire::test(BookingShow::class, ['booking' => $later])
            ->set('rescheduleStart', '2026-11-01T01:30+00:00')
            ->call('reschedule')
            ->assertHasErrors(['rescheduleStart' => 'That time does not exist in this location.']);

        $this->assertSame('2026-11-01 06:30:00', $this->stamp($later, 'starts_at'));
        $this->assertSame('2026-11-01 07:30:00', $this->stamp($later, 'ends_at'));

        Livewire::test(BookingShow::class, ['booking' => $extend])
            ->set('extendEnd', '2026-11-01T01:30')
            ->assertSee('data-extend-choice="2026-11-01T01:30-04:00"', false)
            ->assertSee('data-extend-choice="2026-11-01T01:30-05:00"', false)
            ->call('extend')
            ->assertSet('flash', 'Extended.');

        $this->assertSame('2026-11-01 05:30:00', $this->stamp($extend, 'ends_at'));

        Livewire::test(BookingShow::class, ['booking' => $extend])
            ->call('useExtendEnd', '2026-11-01T01:30-05:00')
            ->assertSet('extendEnd', '2026-11-01T01:30-05:00')
            ->call('extend')
            ->assertSet('flash', 'Extended.');

        $this->assertSame('2026-11-01 06:30:00', $this->stamp($extend, 'ends_at'));
        $keptEnd = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($extend)->ends_at->getTimestamp();

        Livewire::test(BookingShow::class, ['booking' => $extend])
            ->set('extendEnd', '2026-03-08T02:30')
            ->call('extend')
            ->assertHasErrors(['extendEnd' => 'That time does not exist in this location.']);

        $this->assertSame($keptEnd, Booking::withoutGlobalScope(TenantScope::class)->findOrFail($extend)->ends_at->getTimestamp());

        Livewire::test(BookingShow::class, ['booking' => $utcBooking])
            ->assertSet('rescheduleStart', '2026-11-01T01:30')
            ->assertSee('Sun, Nov 1, 1:30 AM UTC')
            ->assertDontSee('data-start-choice=', false)
            ->set('rescheduleStart', '2026-11-01T03:30')
            ->set('rescheduleEnd', '2026-11-01T04:30')
            ->call('reschedule')
            ->assertSet('flash', 'Rescheduled.');

        $this->assertSame('2026-11-01 03:30:00', $this->stamp($utcBooking, 'starts_at'));

        Livewire::test(BookingShow::class, ['booking' => $business])
            ->assertSet('rescheduleStart', '2026-11-01T01:30-05:00')
            ->assertSee('Sun, Nov 1, 1:30 AM CDT')
            ->assertDontSee('Not/AZone');

        $this->assertSame($money, $this->moneyCounts());

        app(TenantManager::class)->set($other['tenant']);
        $this->actingAs($other['user']);
        Livewire::test(BookingShow::class, ['booking' => $creekBooking])->assertOk();
        Livewire::test(BookingShow::class, ['booking' => $later])->assertNotFound();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('2026-11-01 06:30:00', $this->stamp($later, 'starts_at'));
        $this->assertSame('2026-11-01 06:30:00', $this->stamp($creekBooking, 'starts_at'));
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function shop(array $workspace, string $zone, string $name): Service
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => $zone,
            'name' => $name,
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $name.' boats',
            'slug' => str($name)->slug()->toString(),
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name.' boat',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => $name,
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

        return $service;
    }

    private function confirmed(string $host, Service $service, string $start, string $end, string $key): string
    {
        $id = $this->postJson($this->tenantUrl($host, '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->postJson($this->tenantUrl($host, '/api/v1/bookings/'.$id.'/confirm'))->assertOk();

        return $id;
    }

    private function stamp(string $id, string $column): string
    {
        return Booking::withoutGlobalScope(TenantScope::class)->findOrFail($id)->{$column}->utc()->format('Y-m-d H:i:s');
    }

    /**
     * @return array<int, int>
     */
    private function moneyCounts(): array
    {
        return [
            $this->rows(Payment::class),
            $this->rows(Refund::class),
            $this->rows(PlatformFee::class),
            $this->rows(PlatformFeeRefund::class),
            $this->rows(PricingSnapshot::class),
            $this->rows(PriceRule::class),
            $this->rows(LocationTaxRule::class),
        ];
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
