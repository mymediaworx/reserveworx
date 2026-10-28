<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
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
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffCalendarVisitTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_calendar_visits_name_the_location_zone_including_a_daylight_saving_span(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('creek.test');
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->place($workspace['tenant'], 'America/New_York', 'Harbor Kayak');
        $utc = $this->place($workspace['tenant'], 'UTC', 'Utc Kayak');
        $fallback = $this->place($workspace['tenant'], 'Not/AZone', 'Fallback Kayak');
        $creek = $this->place($other['tenant'], 'America/New_York', 'Creek Kayak');

        $spring = $this->booking($workspace['tenant'], $harbor, '2026-03-07 15:00:00', '2026-03-09 15:00:00', 'Span Guest', 'span@example.com');
        $early = $this->booking($workspace['tenant'], $harbor, '2026-11-01 05:30:00', '2026-11-01 05:45:00', 'Early Guest', 'early@example.com');
        $later = $this->booking($workspace['tenant'], $harbor, '2026-11-01 06:30:00', '2026-11-01 06:45:00', 'Later Guest', 'later@example.com');
        $utcVisit = $this->booking($workspace['tenant'], $utc, '2026-06-15 18:00:00', '2026-06-15 19:00:00', 'Utc Guest', 'utc@example.com');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, '2026-06-15 15:00:00', '2026-06-15 16:00:00', 'Fallback Guest', 'fallback@example.com');
        $this->booking($other['tenant'], $creek, '2026-11-01 05:30:00', '2026-11-01 05:45:00', 'Creek Guest', 'creek@example.com');
        $tour = $this->tour($workspace['tenant'], $harbor['location'], 'Jump tour');
        $departure = $this->departure($workspace['tenant'], $tour, '2026-03-08 06:30:00', '2026-03-08 08:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=day&date=2026-11-01'))
            ->assertRedirect('/staff/login');

        $this->actingAs($workspace['user']);

        $fall = $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=day&date=2026-11-01&location='.$harbor['location']->id));
        $fall->assertOk()
            ->assertSee('Early Guest')
            ->assertSee('Later Guest')
            ->assertSee('Sun, Nov 1, 1:30 AM – 1:45 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM – 1:45 AM EST')
            ->assertSee('data-booking="'.$early->id.'"', false)
            ->assertSee('data-booking="'.$later->id.'"', false)
            ->assertSee('.calendar-page .rail-time', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('Creek Guest')
            ->assertDontSee('Creek Kayak');
        $fallDay = $this->daySlice((string) $fall->getContent(), '2026-11-01');
        $this->assertStringNotContainsString('Span Guest', $fallDay);
        $this->assertStringNotContainsString('Jump tour', $fallDay);

        $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=agenda&date=2026-11-01&location='.$harbor['location']->id))
            ->assertOk()
            ->assertSee('Sun, Nov 1, 1:30 AM – 1:45 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM – 1:45 AM EST');

        $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=week&date=2026-11-01&location='.$harbor['location']->id))
            ->assertOk()
            ->assertSee('Sun, Nov 1, 1:30 AM – 1:45 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM – 1:45 AM EST');

        $month = $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=month&date=2026-11-01&location='.$harbor['location']->id));
        $month->assertOk()
            ->assertSee('title="Sun, Nov 1, 1:30 AM – 1:45 AM EDT"', false)
            ->assertSee('title="Sun, Nov 1, 1:30 AM – 1:45 AM EST"', false);

        $springPage = $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=day&date=2026-03-07&location='.$harbor['location']->id));
        $springPage->assertOk()
            ->assertSee('Span Guest')
            ->assertSee('Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT')
            ->assertDontSee('1:30 AM EDT')
            ->assertDontSee('1:30 AM EST');

        $jump = $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=day&date=2026-03-08&location='.$harbor['location']->id));
        $jump->assertOk()
            ->assertSee('Jump tour')
            ->assertSee('Sun, Mar 8, 1:30 AM EST – 4:30 AM EDT')
            ->assertSee('data-departure="'.$departure->id.'"', false)
            ->assertDontSee('Span Guest')
            ->assertDontSee('Mon, Mar 9, 11:00 AM EDT');

        $utcPage = $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=day&date=2026-06-15&location='.$utc['location']->id));
        $utcPage->assertOk()
            ->assertSee('Days follow UTC.')
            ->assertSee('Utc Guest')
            ->assertSee('Mon, Jun 15, 6:00 PM – 7:00 PM UTC')
            ->assertDontSee('EDT')
            ->assertDontSee('CDT')
            ->assertDontSee('CST');

        $fallbackPage = $this->get($this->tenantUrl('dock.test', '/staff/calendar?view=day&date=2026-06-15&location='.$fallback['location']->id));
        $fallbackPage->assertOk()
            ->assertSee('Days follow America/Chicago.')
            ->assertSee('Fallback Guest')
            ->assertSee('Mon, Jun 15, 10:00 AM – 11:00 AM CDT')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('EDT')
            ->assertDontSee('6:00 PM UTC');

        $this->actingAs($other['user']);
        $this->get($this->tenantUrl('creek.test', '/staff/calendar?view=day&date=2026-11-01'))
            ->assertOk()
            ->assertSee('Creek Guest')
            ->assertDontSee('Early Guest')
            ->assertDontSee('Harbor Kayak')
            ->assertDontSee('Jump tour');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $storedSpring = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($spring->id);
        $storedEarly = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($early->id);
        $storedLater = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($later->id);
        $storedUtc = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($utcVisit->id);
        $storedFallback = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($fallbackVisit->id);
        $this->assertSame('2026-03-07 15:00:00', $storedSpring->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $storedSpring->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 05:30:00', $storedEarly->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 05:45:00', $storedEarly->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $storedLater->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:45:00', $storedLater->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 18:00:00', $storedUtc->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 19:00:00', $storedUtc->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 15:00:00', $storedFallback->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-15 16:00:00', $storedFallback->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    /**
     * @return array{location: Location, service: Service}
     */
    private function place(Tenant $tenant, string $timezone, string $name): array
    {
        app(TenantManager::class)->set($tenant);
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name.' Dock',
            'timezone' => $timezone,
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Rental,
        ]);
        app(TenantManager::class)->forget();

        return ['location' => $location, 'service' => $service];
    }

    private function tour(Tenant $tenant, Location $location, string $name): Service
    {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Tour,
        ]);
        app(TenantManager::class)->forget();

        return $service;
    }

    /**
     * @param  array{location: Location, service: Service}  $place
     */
    private function booking(Tenant $tenant, array $place, string $startUtc, string $endUtc, string $name, string $email): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $place['location']->id,
            'service_id' => $place['service']->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => CarbonImmutable::parse($startUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endUtc, 'UTC'),
            'participants' => 2,
            'payload_hash' => hash('sha256', $email),
        ]);
        app(TenantManager::class)->forget();

        return $booking;
    }

    private function departure(Tenant $tenant, Service $service, string $startUtc, string $endUtc): Departure
    {
        app(TenantManager::class)->set($tenant);
        $departure = Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => CarbonImmutable::parse($startUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endUtc, 'UTC'),
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
        app(TenantManager::class)->forget();

        return $departure;
    }

    private function daySlice(string $html, string $date): string
    {
        $needle = 'data-date="'.$date.'"';
        $start = strpos($html, $needle);
        $this->assertNotFalse($start);
        $next = strpos($html, 'data-date="', $start + strlen($needle));

        return $next === false ? substr($html, $start) : substr($html, $start, $next - $start);
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
