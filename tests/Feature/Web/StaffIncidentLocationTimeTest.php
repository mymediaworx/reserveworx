<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Incident;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffIncidentLocationTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_incident_reports_name_the_location_clock_including_both_fall_back_hours(): void
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

        $firstFall = $this->booking($workspace['tenant'], $harbor, 'First Fall', 'first@example.com');
        $secondFall = $this->booking($workspace['tenant'], $harbor, 'Second Fall', 'second@example.com');
        $utcVisit = $this->booking($workspace['tenant'], $utc, 'Utc Guest', 'utc@example.com');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, 'Fallback Guest', 'fallback@example.com');
        $creekVisit = $this->booking($other['tenant'], $creek, 'Creek Only', 'creek@example.com');

        $firstReport = $this->incident($workspace['tenant'], $firstFall, '2026-11-01 05:30:00', 'First hull scrape');
        $secondReport = $this->incident($workspace['tenant'], $secondFall, '2026-11-01 06:30:00', 'Second hull scrape');
        $utcReport = $this->incident($workspace['tenant'], $utcVisit, '2026-06-16 18:00:00', 'Utc ding');
        $fallbackReport = $this->incident($workspace['tenant'], $fallbackVisit, '2026-06-16 18:00:00', 'Fallback ding');
        $this->incident($other['tenant'], $creekVisit, '2026-11-01 05:30:00', 'Creek only scrape');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $fallPage = (string) $this->get($this->tenantUrl('dock.test', '/staff/incidents?status=all&location='.$harbor->location_id))
            ->assertOk()
            ->assertSee('Incidents')
            ->assertSee('First Fall')
            ->assertSee('Second Fall')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('data-incident="'.$firstReport->id.'"', false)
            ->assertSee('data-incident="'.$secondReport->id.'"', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.incidents-page .when { overflow-wrap: anywhere; max-width: 100%; }', false)
            ->assertDontSee('Utc Guest')
            ->assertDontSee('Fallback Guest')
            ->assertDontSee('Creek Only')
            ->assertDontSee('Creek only scrape')
            ->assertDontSee('America/Chicago')
            ->getContent();
        $this->assertSeeInOrder($fallPage, [
            'Sun, Nov 1, 1:30 AM EST',
            'Sun, Nov 1, 1:30 AM EDT',
        ]);

        $this->get($this->tenantUrl('dock.test', '/staff/incidents?status=all&location='.$utc->location_id))
            ->assertOk()
            ->assertSee('Utc Guest')
            ->assertSee('Tue, Jun 16, 6:00 PM UTC')
            ->assertSee('data-incident="'.$utcReport->id.'"', false)
            ->assertDontSee('EDT')
            ->assertDontSee('CDT')
            ->assertDontSee('First Fall');

        $this->get($this->tenantUrl('dock.test', '/staff/incidents?status=all&location='.$fallback->location_id))
            ->assertOk()
            ->assertSee('Fallback Guest')
            ->assertSee('Tue, Jun 16, 1:00 PM CDT')
            ->assertSee('data-incident="'.$fallbackReport->id.'"', false)
            ->assertDontSee('Not/AZone')
            ->assertDontSee('1:00 PM EDT')
            ->assertDontSee('Utc Guest');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $storedFirst = Incident::withoutGlobalScope(TenantScope::class)->findOrFail($firstReport->id);
        $storedSecond = Incident::withoutGlobalScope(TenantScope::class)->findOrFail($secondReport->id);
        $this->assertSame('2026-11-01 05:30:00', $storedFirst->occurred_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $storedSecond->occurred_at?->utc()->format('Y-m-d H:i:s'));
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

    private function booking(Tenant $tenant, Service $service, string $customerName, string $email): Booking
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
            'starts_at' => CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-11-01 14:00:00', 'UTC'),
            'participants' => 1,
            'payload_hash' => hash('sha256', $email),
        ]);
        app(TenantManager::class)->forget();

        return $booking;
    }

    private function incident(Tenant $tenant, Booking $booking, string $occurredUtc, string $notes): Incident
    {
        app(TenantManager::class)->set($tenant);
        $incident = Incident::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'severity' => IncidentSeverity::Minor,
            'summary' => $notes,
            'notes' => $notes,
            'status' => IncidentStatus::Open,
            'occurred_at' => CarbonImmutable::parse($occurredUtc, 'UTC'),
        ]);
        app(TenantManager::class)->forget();

        return $incident;
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertSeeInOrder(string $html, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = strpos($html, $needle, $offset);
            $this->assertNotFalse($position, $needle);
            $offset = $position + strlen($needle);
        }
    }
}
