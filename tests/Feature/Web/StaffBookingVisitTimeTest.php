<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
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
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffBookingVisitTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_booking_times_name_the_location_zone_including_a_daylight_saving_span(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-08 12:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->service($workspace['tenant'], 'America/New_York', 'Harbor Kayak');
        $utc = $this->service($workspace['tenant'], 'UTC', 'Utc Kayak');
        $fallback = $this->service($workspace['tenant'], 'Not/AZone', 'Fallback Kayak');
        $creek = $this->service($other['tenant'], 'America/New_York', 'Creek Kayak');

        $spring = $this->booking($workspace['tenant'], $harbor, '2026-03-07 15:00:00', '2026-03-09 15:00:00', 'spring@example.com');
        $fall = $this->booking($workspace['tenant'], $harbor, '2026-10-31 14:00:00', '2026-11-02 14:00:00', 'fall@example.com');
        $july = $this->booking($workspace['tenant'], $harbor, '2026-07-04 14:00:00', '2026-07-04 15:00:00', 'july@example.com');
        $utcVisit = $this->booking($workspace['tenant'], $utc, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'utc@example.com');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'fallback@example.com');
        $creekVisit = $this->booking($other['tenant'], $creek, '2026-03-07 15:00:00', '2026-03-09 15:00:00', 'creek@example.com');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $list = $this->get($this->tenantUrl('dock.test', '/staff/bookings'));
        $list->assertOk()
            ->assertSee('Harbor Kayak')
            ->assertSee('Sat, Mar 7, 10:00 AM EST')
            ->assertSee('Sat, Oct 31, 10:00 AM EDT')
            ->assertSee('Sat, Jul 4, 10:00 AM EDT')
            ->assertSee('Tue, Jun 16, 3:00 PM UTC')
            ->assertSee('Tue, Jun 16, 10:00 AM CDT')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Mon, Mar 9, 11:00 AM EDT')
            ->assertDontSee('Mon, Nov 2, 9:00 AM EST')
            ->assertDontSee('9:00 AM CST')
            ->assertDontSee('Creek Kayak')
            ->assertDontSee('America/Chicago');

        $springPage = $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$spring->id));
        $springPage->assertOk()
            ->assertSee('Harbor Kayak')
            ->assertSee('Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('9:00 AM CST')
            ->assertDontSee('10:00 AM CDT')
            ->assertDontSee('America/Chicago')
            ->assertDontSee('Creek Kayak');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$fall->id))
            ->assertOk()
            ->assertSee('Sat, Oct 31, 10:00 AM EDT – Mon, Nov 2, 9:00 AM EST')
            ->assertDontSee('9:00 AM CDT')
            ->assertDontSee('8:00 AM CST');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$july->id))
            ->assertOk()
            ->assertSee('Sat, Jul 4, 10:00 AM – 11:00 AM EDT')
            ->assertDontSee('9:00 AM CDT');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$utcVisit->id))
            ->assertOk()
            ->assertSee('Utc Kayak')
            ->assertSee('Tue, Jun 16, 3:00 PM – 4:00 PM UTC')
            ->assertDontSee('EDT')
            ->assertDontSee('CDT');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$fallbackVisit->id))
            ->assertOk()
            ->assertSee('Fallback Kayak')
            ->assertSee('Tue, Jun 16, 10:00 AM – 11:00 AM CDT')
            ->assertDontSee('11:00 AM EDT')
            ->assertDontSee('America/New_York');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$creekVisit->id))
            ->assertNotFound()
            ->assertDontSee('Creek Kayak');

        $this->get($this->tenantUrl('creek.test', '/staff/bookings/'.$spring->id))
            ->assertForbidden()
            ->assertDontSee('10:00 AM EST')
            ->assertDontSee('Harbor Kayak');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $storedSpring = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($spring->id);
        $storedFall = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($fall->id);
        $this->assertSame('2026-03-07 15:00:00', $storedSpring->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $storedSpring->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 14:00:00', $storedFall->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 14:00:00', $storedFall->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    private function service(Tenant $tenant, string $timezone, string $name): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => $timezone,
            'name' => $name.' Dock',
        ]);
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Rental,
        ]);
        app(TenantManager::class)->forget();

        return $service;
    }

    private function booking(Tenant $tenant, Service $service, string $startUtc, string $endUtc, string $email): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Ada',
            'email' => $email,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => CarbonImmutable::parse($startUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endUtc, 'UTC'),
            'participants' => 1,
            'payload_hash' => hash('sha256', $email),
        ]);
        app(TenantManager::class)->forget();

        return $booking;
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
