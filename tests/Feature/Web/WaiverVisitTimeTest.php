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
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\Waivers\WaiverLinks;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\AssertsZoneAbbreviations;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class WaiverVisitTimeTest extends TestCase
{
    use AssertsZoneAbbreviations, CreatesWorkspace, RefreshDatabase;

    private const TEXT = 'I understand paddling has risks and I accept them for myself.';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_waiver_page_names_the_location_zone_including_a_daylight_saving_span(): void
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
        $this->publishWaiver($workspace['tenant']);

        $spring = $this->booking($workspace['tenant'], $harbor, '2026-03-07 15:00:00', '2026-03-09 15:00:00', 'spring@example.com');
        $fall = $this->booking($workspace['tenant'], $harbor, '2026-10-31 14:00:00', '2026-11-02 14:00:00', 'fall@example.com');
        $july = $this->booking($workspace['tenant'], $harbor, '2026-07-04 14:00:00', '2026-07-04 15:00:00', 'july@example.com');
        $utcVisit = $this->booking($workspace['tenant'], $utc, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'utc@example.com');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'fallback@example.com');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $audits = $this->rows(AuditLog::class);
        $signatures = $this->rows(WaiverSignature::class);

        $springPage = $this->get($this->link('dock.test', $spring));
        $springPage->assertOk()
            ->assertSee('Sign your waiver')
            ->assertSee('Harbor Kayak')
            ->assertSee('Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('9:00 AM CST')
            ->assertDontSee('10:00 AM CDT')
            ->assertDontSee('America/Chicago');

        $this->get($this->link('dock.test', $fall))
            ->assertOk()
            ->assertSee('Sat, Oct 31, 10:00 AM EDT – Mon, Nov 2, 9:00 AM EST')
            ->assertDontSee('9:00 AM CDT')
            ->assertDontSee('8:00 AM CST');

        $this->get($this->link('dock.test', $july))
            ->assertOk()
            ->assertSee('Sat, Jul 4, 10:00 AM – 11:00 AM EDT')
            ->assertDontSee('9:00 AM CDT');

        $utcPage = $this->get($this->link('dock.test', $utcVisit))
            ->assertOk()
            ->assertSee('Utc Kayak')
            ->assertSee('Tue, Jun 16, 3:00 PM – 4:00 PM UTC');
        $this->assertOmitsZoneAbbreviations($utcPage, 'EDT', 'CDT');

        $this->get($this->link('dock.test', $fallbackVisit))
            ->assertOk()
            ->assertSee('Fallback Kayak')
            ->assertSee('Tue, Jun 16, 10:00 AM – 11:00 AM CDT')
            ->assertDontSee('11:00 AM EDT')
            ->assertDontSee('America/New_York');

        $this->get(str_replace('http://dock.test', 'http://creek.test', $this->link('dock.test', $spring)))
            ->assertNotFound()
            ->assertDontSee('10:00 AM EST')
            ->assertDontSee('Harbor Kayak');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));
        $this->assertSame($signatures, $this->rows(WaiverSignature::class));
        $this->assertSame(0, $signatures);

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

    private function publishWaiver(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        WaiverVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Liability waiver',
            'version' => 1,
            'body' => self::TEXT,
            'content_hash' => hash('sha256', self::TEXT),
        ]);
        app(TenantManager::class)->forget();
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

    private function link(string $host, Booking $booking): string
    {
        URL::forceRootUrl('http://'.$host);

        try {
            return app(WaiverLinks::class)->url($booking);
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
