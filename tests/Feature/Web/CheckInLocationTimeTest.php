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
use Illuminate\Support\Facades\URL;
use Tests\Support\AssertsZoneAbbreviations;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CheckInLocationTimeTest extends TestCase
{
    use AssertsZoneAbbreviations, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_todays_arrivals_follow_each_location_day_and_name_the_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 18:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->service($workspace['tenant'], 'America/New_York', 'Harbor Kayak');
        $pacific = $this->service($workspace['tenant'], 'America/Los_Angeles', 'Pacific Kayak');
        $creek = $this->service($other['tenant'], 'America/New_York', 'Creek Kayak');

        $firstFall = $this->booking($workspace['tenant'], $harbor, '2026-11-01 05:30:00', '2026-11-01 06:00:00', 'first-fall@example.com', 'First Fall');
        $secondFall = $this->booking($workspace['tenant'], $harbor, '2026-11-01 06:30:00', '2026-11-01 07:00:00', 'second-fall@example.com', 'Second Fall');
        $latePacific = $this->booking($workspace['tenant'], $pacific, '2026-11-02 06:30:00', '2026-11-02 07:30:00', 'late-pacific@example.com', 'Late Pacific');
        $creekVisit = $this->booking($other['tenant'], $creek, '2026-11-01 05:30:00', '2026-11-01 06:00:00', 'creek@example.com', 'Creek Guest');
        $springEarly = $this->booking($workspace['tenant'], $harbor, '2026-03-08 06:30:00', '2026-03-08 07:00:00', 'spring-early@example.com', 'Spring Early');
        $springAfter = $this->booking($workspace['tenant'], $harbor, '2026-03-08 07:30:00', '2026-03-08 08:00:00', 'spring-after@example.com', 'Spring After');
        $nextMorning = $this->booking($workspace['tenant'], $harbor, '2026-03-09 04:30:00', '2026-03-09 05:00:00', 'next-morning@example.com', 'Next Morning');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $harborList = $this->get($this->tenantUrl('dock.test', '/staff/check-in?location='.$harbor->location_id));
        $harborList->assertOk()
            ->assertSee('Times are in America/New_York')
            ->assertSee('First Fall')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Second Fall')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Late Pacific')
            ->assertDontSee('10:30 PM PST')
            ->assertDontSee('Creek Kayak')
            ->assertDontSee('Creek Guest')
            ->assertDontSee('America/Chicago');
        $this->assertOmitsZoneAbbreviations($harborList, 'CDT', 'CST');

        $pacificList = $this->get($this->tenantUrl('dock.test', '/staff/check-in?location='.$pacific->location_id));
        $pacificList->assertOk()
            ->assertSee('Times are in America/Los_Angeles')
            ->assertSee('Late Pacific')
            ->assertSee('Sun, Nov 1, 10:30 PM PST')
            ->assertDontSee('First Fall')
            ->assertDontSee('1:30 AM EDT')
            ->assertDontSee('Creek Guest');

        Carbon::setTestNow(Carbon::parse('2026-03-08 16:00:00', 'UTC'));

        $springList = $this->get($this->tenantUrl('dock.test', '/staff/check-in?location='.$harbor->location_id));
        $springList->assertOk()
            ->assertSee('Times are in America/New_York')
            ->assertSee('Spring Early')
            ->assertSee('Sun, Mar 8, 1:30 AM EST')
            ->assertSee('Spring After')
            ->assertSee('Sun, Mar 8, 3:30 AM EDT')
            ->assertDontSee('Next Morning')
            ->assertDontSee('Mon, Mar 9, 12:30 AM EDT')
            ->assertDontSee('First Fall')
            ->assertDontSee('America/Chicago');
        $this->assertOmitsZoneAbbreviations($springList, 'CDT');

        $this->get($this->tenantUrl('creek.test', '/staff/check-in?location='.$harbor->location_id))
            ->assertForbidden()
            ->assertDontSee('Harbor Kayak')
            ->assertDontSee('1:30 AM EST');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        foreach ([$firstFall, $secondFall, $latePacific, $creekVisit, $springEarly, $springAfter, $nextMorning] as $booking) {
            $stored = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($booking->id);
            $this->assertSame(OperationalStatus::NotArrived, $stored->operational_status);
            $this->assertSame($booking->starts_at->utc()->format('Y-m-d H:i:s'), $stored->starts_at->utc()->format('Y-m-d H:i:s'));
            $this->assertSame($booking->ends_at->utc()->format('Y-m-d H:i:s'), $stored->ends_at->utc()->format('Y-m-d H:i:s'));
        }
    }

    public function test_the_check_in_page_names_the_visit_in_the_location_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 16:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->service($workspace['tenant'], 'America/New_York', 'Harbor Kayak');
        $utc = $this->service($workspace['tenant'], 'UTC', 'Utc Kayak');
        $fallback = $this->service($workspace['tenant'], 'Not/AZone', 'Fallback Kayak');
        $creek = $this->service($other['tenant'], 'America/New_York', 'Creek Kayak');

        $spring = $this->booking($workspace['tenant'], $harbor, '2026-03-07 15:00:00', '2026-03-09 15:00:00', 'spring@example.com', 'Spring Guest');
        $fall = $this->booking($workspace['tenant'], $harbor, '2026-10-31 14:00:00', '2026-11-02 14:00:00', 'fall@example.com', 'Fall Guest');
        $july = $this->booking($workspace['tenant'], $harbor, '2026-07-04 14:00:00', '2026-07-04 15:00:00', 'july@example.com', 'July Guest');
        $utcVisit = $this->booking($workspace['tenant'], $utc, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'utc@example.com', 'Utc Guest');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'fallback@example.com', 'Fallback Guest');
        $creekVisit = $this->booking($other['tenant'], $creek, '2026-03-07 15:00:00', '2026-03-09 15:00:00', 'creek@example.com', 'Creek Guest');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);

        $this->actingAs($workspace['user']);

        $this->get($this->signedUrl('dock.test', $spring->id))
            ->assertOk()
            ->assertSee('Harbor Kayak')
            ->assertSee('Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('9:00 AM CST')
            ->assertDontSee('10:00 AM CDT')
            ->assertDontSee('America/Chicago');

        $this->get($this->signedUrl('dock.test', $fall->id))
            ->assertOk()
            ->assertSee('Sat, Oct 31, 10:00 AM EDT – Mon, Nov 2, 9:00 AM EST')
            ->assertDontSee('9:00 AM CDT')
            ->assertDontSee('8:00 AM CST');

        $this->get($this->signedUrl('dock.test', $july->id))
            ->assertOk()
            ->assertSee('Sat, Jul 4, 10:00 AM – 11:00 AM EDT')
            ->assertDontSee('9:00 AM CDT');

        $utcPage = $this->get($this->signedUrl('dock.test', $utcVisit->id))
            ->assertOk()
            ->assertSee('Utc Kayak')
            ->assertSee('Tue, Jun 16, 3:00 PM – 4:00 PM UTC');
        $this->assertOmitsZoneAbbreviations($utcPage, 'EDT', 'CDT');

        $this->get($this->signedUrl('dock.test', $fallbackVisit->id))
            ->assertOk()
            ->assertSee('Fallback Kayak')
            ->assertSee('Tue, Jun 16, 10:00 AM – 11:00 AM CDT')
            ->assertDontSee('11:00 AM EDT')
            ->assertDontSee('America/New_York');

        $this->get($this->signedUrl('dock.test', $creekVisit->id))
            ->assertNotFound()
            ->assertDontSee('Creek Kayak');

        $this->actingAs($other['user']);
        $this->get($this->signedUrl('creek.test', $spring->id))
            ->assertNotFound()
            ->assertDontSee('Harbor Kayak')
            ->assertDontSee('10:00 AM EST');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));

        $storedSpring = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($spring->id);
        $storedFall = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($fall->id);
        $this->assertSame('2026-03-07 15:00:00', $storedSpring->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $storedSpring->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 14:00:00', $storedFall->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 14:00:00', $storedFall->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(OperationalStatus::CheckedIn, $storedSpring->operational_status);
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

    private function booking(Tenant $tenant, Service $service, string $startUtc, string $endUtc, string $email, string $customerName): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
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

    private function signedUrl(string $host, string $bookingId): string
    {
        URL::forceRootUrl('http://'.$host);

        try {
            return URL::signedRoute('staff.check-in.arrive', ['booking' => $bookingId]);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
