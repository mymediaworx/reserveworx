<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Hold;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsZoneAbbreviations;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffHoldExpiryLocationTimeTest extends TestCase
{
    use AssertsZoneAbbreviations, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_open_hold_names_its_deadline_in_the_location_zone(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->service($workspace['tenant'], 'America/New_York', 'Harbor Kayak');
        $utc = $this->service($workspace['tenant'], 'UTC', 'Utc Kayak');
        $fallback = $this->service($workspace['tenant'], 'Not/AZone', 'Fallback Kayak');
        $creek = $this->service($other['tenant'], 'America/New_York', 'Creek Kayak');

        $earlier = $this->booking($workspace['tenant'], $harbor, BookingStatus::Held, 'Earlier Fall', 'earlier@example.com', '2026-11-01 05:00:00', '2026-11-01 06:00:00');
        $later = $this->booking($workspace['tenant'], $harbor, BookingStatus::PendingPayment, 'Later Fall', 'later@example.com', '2026-11-01 06:00:00', '2026-11-01 07:00:00');
        $spring = $this->booking($workspace['tenant'], $harbor, BookingStatus::Held, 'Spring Guest', 'spring@example.com', '2026-03-08 07:00:00', '2026-03-08 08:00:00');
        $utcVisit = $this->booking($workspace['tenant'], $utc, BookingStatus::Held, 'Utc Guest', 'utc@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, BookingStatus::Held, 'Fallback Guest', 'fallback@example.com', '2026-06-16 20:00:00', '2026-06-16 21:00:00');
        $confirmed = $this->booking($workspace['tenant'], $harbor, BookingStatus::Confirmed, 'Confirmed Guest', 'confirmed@example.com', '2026-11-01 08:00:00', '2026-11-01 09:00:00');
        $creekVisit = $this->booking($other['tenant'], $creek, BookingStatus::Held, 'Creek Only', 'creek@example.com', '2026-11-01 05:30:00', '2026-11-01 06:30:00');

        $this->hold($workspace['tenant'], $earlier, '2026-11-01 05:30:00');
        $this->hold($workspace['tenant'], $later, '2026-11-01 06:30:00');
        $this->hold($workspace['tenant'], $spring, '2026-03-08 07:30:00');
        $this->hold($workspace['tenant'], $utcVisit, '2026-06-16 15:00:00');
        $this->hold($workspace['tenant'], $fallbackVisit, '2026-06-16 20:00:00');
        $this->hold($workspace['tenant'], $confirmed, '2026-11-01 08:45:00');
        $this->hold($other['tenant'], $creekVisit, '2026-11-01 05:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $earlierPage = $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$earlier->id));
        $earlierPage->assertOk()
            ->assertSee('Earlier Fall')
            ->assertSee('Held until 1:30 AM EDT')
            ->assertDontSee('1:30 AM EST')
            ->assertDontSee('Waiting for payment until')
            ->assertSee('data-hold-until', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Creek Only')
            ->assertDontSee('America/Chicago');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$later->id))
            ->assertOk()
            ->assertSee('Later Fall')
            ->assertSee('Waiting for payment until 1:30 AM EST')
            ->assertDontSee('Held until 1:30 AM')
            ->assertDontSee('1:30 AM EDT');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$spring->id))
            ->assertOk()
            ->assertSee('Held until 3:30 AM EDT')
            ->assertDontSee('2:30 AM');

        $utcPage = $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$utcVisit->id));
        $utcPage->assertOk()
            ->assertSee('Utc Guest')
            ->assertSee('Held until 3:00 PM UTC');
        $this->assertOmitsZoneAbbreviations($utcPage, 'EDT', 'EST', 'CDT');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$fallbackVisit->id))
            ->assertOk()
            ->assertSee('Fallback Guest')
            ->assertSee('Held until 3:00 PM CDT')
            ->assertDontSee('America/New_York');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$confirmed->id))
            ->assertOk()
            ->assertSee('Confirmed Guest')
            ->assertDontSee('Held until')
            ->assertDontSee('Waiting for payment until')
            ->assertDontSee('4:45 AM EDT');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$creekVisit->id))
            ->assertNotFound()
            ->assertDontSee('Creek Only');

        $this->actingAs($other['user']);
        $this->get($this->tenantUrl('creek.test', '/staff/bookings/'.$earlier->id))
            ->assertNotFound()
            ->assertDontSee('Earlier Fall')
            ->assertDontSee('1:30 AM EDT');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $storedEarlier = Hold::withoutGlobalScope(TenantScope::class)->where('booking_id', $earlier->id)->firstOrFail();
        $storedLater = Hold::withoutGlobalScope(TenantScope::class)->where('booking_id', $later->id)->firstOrFail();
        $this->assertSame('2026-11-01 05:30:00', $storedEarlier->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $storedLater->expires_at->utc()->format('Y-m-d H:i:s'));
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

    private function booking(Tenant $tenant, Service $service, BookingStatus $status, string $name, string $email, string $startUtc, string $endUtc): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => $status,
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

    private function hold(Tenant $tenant, Booking $booking, string $expiresAtUtc): void
    {
        app(TenantManager::class)->set($tenant);
        Hold::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'quantity' => 1,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
            'expires_at' => CarbonImmutable::parse($expiresAtUtc, 'UTC'),
        ]);
        app(TenantManager::class)->forget();
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
