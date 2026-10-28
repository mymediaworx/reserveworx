<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Maintenance;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MaintenanceWindow;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffMaintenanceLocationTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_maintenance_windows_name_the_location_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->place($workspace['tenant'], 'America/New_York', 'Harbor boat');
        $utc = $this->place($workspace['tenant'], 'UTC', 'Utc boat');
        $fallback = $this->place($workspace['tenant'], 'Not/AZone', 'Fallback boat');
        $creek = $this->place($other['tenant'], 'America/New_York', 'Creek boat');

        $this->window($harbor, '2026-10-15 12:00:00', '2026-10-15 18:00:00', 'Oil change');
        $spring = $this->window($harbor, '2026-03-07 15:00:00', '2026-03-09 15:00:00', 'Spring service');
        $firstFall = $this->window($harbor, '2026-11-01 05:30:00', '2026-11-01 06:00:00', 'First hour');
        $secondFall = $this->window($harbor, '2026-11-01 06:30:00', '2026-11-01 07:30:00', 'Second hour');
        $ended = $this->window($harbor, '2026-02-01 14:00:00', '2026-02-01 18:00:00', 'Ended early', '2026-02-01 15:00:00');
        $this->window($utc, '2026-10-20 15:00:00', '2026-10-20 16:00:00', 'Utc service');
        $this->window($fallback, '2026-10-20 15:00:00', '2026-10-20 16:00:00', 'Fallback service');
        $this->window($creek, '2026-11-01 05:30:00', '2026-11-01 06:30:00', 'Creek service');
        $guest = $this->overlap($harbor, $firstFall, '2026-11-01 05:30:00', '2026-11-01 06:00:00', 'First Fall');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $harborPage = $this->get($this->tenantUrl('dock.test', '/staff/maintenance?location='.$harbor->location_id));
        $harborPage->assertOk()
            ->assertSee('Times are in America/New_York')
            ->assertSee('Oil change')
            ->assertSee('Thu, Oct 15, 8:00 AM – 2:00 PM EDT')
            ->assertSee('Spring service')
            ->assertSee('Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT')
            ->assertSee('First hour')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT – 1:00 AM EST')
            ->assertSee('Second hour')
            ->assertSee('Sun, Nov 1, 1:30 AM – 2:30 AM EST')
            ->assertSee('First Fall')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Ended early')
            ->assertSee('Ended Sun, Feb 1, 10:00 AM EST')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Utc service')
            ->assertDontSee('3:00 PM UTC')
            ->assertDontSee('Fallback service')
            ->assertDontSee('Creek service')
            ->assertDontSee('Creek boat')
            ->assertDontSee('CDT')
            ->assertDontSee('CST')
            ->assertDontSee('America/Chicago');

        $this->get($this->tenantUrl('dock.test', '/staff/maintenance?location='.$utc->location_id))
            ->assertOk()
            ->assertSee('Times are in UTC')
            ->assertSee('Utc boat')
            ->assertSee('Tue, Oct 20, 3:00 PM – 4:00 PM UTC')
            ->assertDontSee('Oil change')
            ->assertDontSee('EDT')
            ->assertDontSee('CDT');

        $this->get($this->tenantUrl('dock.test', '/staff/maintenance?location='.$fallback->location_id))
            ->assertOk()
            ->assertSee('Times are in America/Chicago')
            ->assertSee('Fallback boat')
            ->assertSee('Tue, Oct 20, 10:00 AM – 11:00 AM CDT')
            ->assertDontSee('11:00 AM EDT')
            ->assertDontSee('America/New_York')
            ->assertDontSee('Oil change');

        $this->get($this->tenantUrl('dock.test', '/staff/maintenance'))
            ->assertOk()
            ->assertSee("Times use each location's time zone.", false)
            ->assertSee('Sun, Nov 1, 1:30 AM EDT – 1:00 AM EST')
            ->assertSee('Tue, Oct 20, 3:00 PM – 4:00 PM UTC')
            ->assertSee('Tue, Oct 20, 10:00 AM – 11:00 AM CDT')
            ->assertDontSee('Creek service');

        $this->get($this->tenantUrl('creek.test', '/staff/maintenance?location='.$harbor->location_id))
            ->assertForbidden()
            ->assertDontSee('Harbor boat')
            ->assertDontSee('1:30 AM EDT');

        $this->actingAs($other['user']);
        $this->get($this->tenantUrl('creek.test', '/staff/maintenance?location='.$harbor->location_id))
            ->assertNotFound()
            ->assertDontSee('Harbor boat')
            ->assertDontSee('Oil change');
        $this->get($this->tenantUrl('creek.test', '/staff/maintenance'))
            ->assertOk()
            ->assertSee('Creek service')
            ->assertDontSee('Harbor boat')
            ->assertDontSee('First hour')
            ->assertDontSee('Oil change');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $stored = MaintenanceWindow::withoutGlobalScope(TenantScope::class)->findOrFail($spring->id);
        $this->assertSame('2026-03-07 15:00:00', $stored->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $stored->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($guest->starts_at->utc()->format('Y-m-d H:i:s'), Booking::withoutGlobalScope(TenantScope::class)->findOrFail($guest->id)->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-01 15:00:00', MaintenanceWindow::withoutGlobalScope(TenantScope::class)->findOrFail($ended->id)->ended_at?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_missing_hour_is_refused_and_a_repeated_hour_keeps_both_instants(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 15:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);
        $harbor = $this->place($workspace['tenant'], 'America/New_York', 'Harbor boat');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(Maintenance::class)
            ->set('windowResource', 'asset:'.$harbor->id)
            ->set('windowKind', 'maintenance')
            ->set('windowStarts', '2026-03-08T02:30')
            ->set('windowEnds', '2026-03-08T04:00')
            ->set('windowReason', 'Missing hour')
            ->call('scheduleWindow')
            ->assertHasErrors(['windowStarts' => 'That time does not exist in this location.'])
            ->assertDontSee('Maintenance scheduled.');

        $this->assertSame(0, MaintenanceWindow::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, $this->rows(AuditLog::class));

        Livewire::test(Maintenance::class)
            ->set('windowResource', 'asset:'.$harbor->id)
            ->set('windowKind', 'maintenance')
            ->set('windowStarts', '2026-11-01T01:30+00:00')
            ->set('windowEnds', '2026-11-01T03:00')
            ->set('windowReason', 'Wrong offset')
            ->call('scheduleWindow')
            ->assertHasErrors(['windowStarts' => 'That time does not exist in this location.']);

        $this->assertSame(0, MaintenanceWindow::withoutGlobalScope(TenantScope::class)->count());

        Livewire::test(Maintenance::class)
            ->set('windowResource', 'asset:'.$harbor->id)
            ->set('windowKind', 'block')
            ->set('windowStarts', '2026-11-01T01:30')
            ->set('windowEnds', '2026-11-01T02:30')
            ->set('windowReason', 'Both hours')
            ->call('scheduleWindow')
            ->assertHasNoErrors();

        $both = MaintenanceWindow::withoutGlobalScope(TenantScope::class)->sole();
        $this->assertSame('2026-11-01 05:30:00', $both->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 07:30:00', $both->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($workspace['tenant']->id, $both->tenant_id);

        Livewire::test(Maintenance::class)
            ->set('windowResource', 'asset:'.$harbor->id)
            ->set('windowKind', 'maintenance')
            ->set('windowStarts', '2026-11-01T01:30-05:00')
            ->set('windowEnds', '2026-11-01T03:00')
            ->set('windowReason', 'Later hour')
            ->call('scheduleWindow')
            ->assertHasNoErrors();

        $later = MaintenanceWindow::withoutGlobalScope(TenantScope::class)->where('reason', 'Later hour')->sole();
        $this->assertSame('2026-11-01 06:30:00', $later->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 08:00:00', $later->ends_at->utc()->format('Y-m-d H:i:s'));

        $this->get($this->tenantUrl('dock.test', '/staff/maintenance?location='.$harbor->location_id))
            ->assertOk()
            ->assertSee('Times are in America/New_York')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT – 2:30 AM EST')
            ->assertSee('Sun, Nov 1, 1:30 AM – 3:00 AM EST')
            ->assertDontSee('CDT')
            ->assertDontSee('America/Chicago');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame(2, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'maintenance.scheduled')->count());
    }

    private function place(Tenant $tenant, string $timezone, string $name): Asset
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => $timezone,
            'name' => $name.' dock',
        ]);

        return Asset::factory()->create([
            'location_id' => $location->id,
            'name' => $name,
        ]);
    }

    private function window(Asset $asset, string $startUtc, string $endUtc, string $reason, ?string $endedUtc = null): MaintenanceWindow
    {
        $this->useTenant($asset);

        $window = MaintenanceWindow::query()->create([
            'tenant_id' => $asset->tenant_id,
            'asset_id' => $asset->id,
            'kind' => 'maintenance',
            'starts_at' => CarbonImmutable::parse($startUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endUtc, 'UTC'),
            'ended_at' => $endedUtc === null ? null : CarbonImmutable::parse($endedUtc, 'UTC'),
            'reason' => $reason,
        ]);

        app(TenantManager::class)->forget();

        return $window;
    }

    private function overlap(Asset $asset, MaintenanceWindow $window, string $startUtc, string $endUtc, string $customerName): Booking
    {
        $this->useTenant($asset);
        $service = Service::query()->create([
            'tenant_id' => $asset->tenant_id,
            'location_id' => $asset->location_id,
            'name' => 'Harbor rental',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $asset->tenant_id,
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '-', $customerName)).'@example.com',
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $asset->tenant_id,
            'location_id' => $asset->location_id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => CarbonImmutable::parse($startUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endUtc, 'UTC'),
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerName),
        ]);
        Allocation::query()->create([
            'tenant_id' => $asset->tenant_id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'starts_at' => $window->starts_at,
            'ends_at' => $window->ends_at,
        ]);
        app(TenantManager::class)->forget();

        return $booking;
    }

    private function useTenant(Asset $asset): void
    {
        app(TenantManager::class)->set(Tenant::query()->findOrFail($asset->tenant_id));
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
