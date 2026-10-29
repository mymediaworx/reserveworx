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
use Tests\Support\AssertsZoneAbbreviations;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffManifestLocationTimeTest extends TestCase
{
    use AssertsZoneAbbreviations, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_manifest_names_each_visit_in_the_location_zone_including_both_fall_back_hours(): void
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

        $spring = $this->booking($workspace['tenant'], $harbor, '2026-03-08 03:00:00', '2026-03-08 08:00:00', 'Spring Span', 'spring@example.com');
        $fallSpan = $this->booking($workspace['tenant'], $harbor, '2026-11-01 02:00:00', '2026-11-01 08:00:00', 'Fall Span', 'fallspan@example.com');
        $firstFall = $this->booking($workspace['tenant'], $harbor, '2026-11-01 05:30:00', '2026-11-01 06:30:00', 'First Fall', 'first@example.com');
        $secondFall = $this->booking($workspace['tenant'], $harbor, '2026-11-01 06:30:00', '2026-11-01 07:30:00', 'Second Fall', 'second@example.com');
        $utcVisit = $this->booking($workspace['tenant'], $utc, '2026-06-16 18:00:00', '2026-06-16 20:00:00', 'Utc Guest', 'utc@example.com');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, '2026-06-16 18:00:00', '2026-06-16 19:00:00', 'Fallback Guest', 'fallback@example.com');
        $this->booking($other['tenant'], $creek, '2026-03-08 03:00:00', '2026-03-08 08:00:00', 'Creek Only', 'creek@example.com');
        $departure = $this->departure($workspace['tenant'], $harbor, '2026-03-08 03:30:00', '2026-03-08 07:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $springPage = $this->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-03-07'));
        $springPage->assertOk()
            ->assertSee('Dispatch manifest')
            ->assertSee('Harbor Kayak')
            ->assertSee('Sunrise Tour')
            ->assertSee('Sat, Mar 7, 10:00 PM EST – Sun, Mar 8, 4:00 AM EDT')
            ->assertSee('Sat, Mar 7, 10:30 PM EST – Sun, Mar 8, 3:30 AM EDT')
            ->assertSee('data-departure="'.$departure->id.'"', false)
            ->assertSee('data-booking="'.$spring->id.'" data-local-start="2026-03-07T22:00" data-return-due="2026-03-08T04:00"', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.manifest-page .when { overflow-wrap: anywhere; max-width: 100%; }', false)
            ->assertDontSee('9:00 PM CST')
            ->assertDontSee('America/Chicago')
            ->assertDontSee('Creek Only')
            ->assertDontSee('Creek Kayak');

        $this->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-10-31'))
            ->assertOk()
            ->assertSee('Fall Span')
            ->assertSee('Sat, Oct 31, 10:00 PM EDT – Sun, Nov 1, 3:00 AM EST')
            ->assertSee('data-booking="'.$fallSpan->id.'" data-local-start="2026-10-31T22:00" data-return-due="2026-11-01T03:00"', false)
            ->assertDontSee('9:00 PM CDT');

        $fallPage = (string) $this->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-11-01'))
            ->assertOk()
            ->assertSee('First Fall')
            ->assertSee('Second Fall')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT – 1:30 AM EST')
            ->assertSee('Sun, Nov 1, 1:30 AM – 2:30 AM EST')
            ->assertSee('data-booking="'.$firstFall->id.'" data-local-start="2026-11-01T01:30" data-return-due="2026-11-01T01:30"', false)
            ->assertSee('data-booking="'.$secondFall->id.'" data-local-start="2026-11-01T01:30" data-return-due="2026-11-01T02:30"', false)
            ->getContent();
        $this->assertSeeInOrder($fallPage, [
            'Sun, Nov 1, 1:30 AM EDT – 1:30 AM EST',
            'Sun, Nov 1, 1:30 AM EST',
            'Sun, Nov 1, 1:30 AM – 2:30 AM EST',
        ]);

        $utcManifest = $this->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-06-16&location='.$utc->location_id))
            ->assertOk()
            ->assertSee('Utc Guest')
            ->assertSee('Tue, Jun 16, 6:00 PM – 8:00 PM UTC')
            ->assertSee('data-booking="'.$utcVisit->id.'" data-local-start="2026-06-16T18:00" data-return-due="2026-06-16T20:00"', false)
            ->assertDontSee('First Fall');
        $this->assertOmitsZoneAbbreviations($utcManifest, 'EDT', 'CDT');

        $this->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-06-16&location='.$fallback->location_id))
            ->assertOk()
            ->assertSee('Fallback Guest')
            ->assertSee('Tue, Jun 16, 1:00 PM – 2:00 PM CDT')
            ->assertSee('America/Chicago')
            ->assertSee('data-booking="'.$fallbackVisit->id.'" data-local-start="2026-06-16T13:00" data-return-due="2026-06-16T14:00"', false)
            ->assertDontSee('Not/AZone')
            ->assertDontSee('1:00 PM EDT')
            ->assertDontSee('Utc Guest');

        $fallCsv = $this->byId($this->csvRows((string) $this->get($this->tenantUrl('dock.test', '/staff/manifest.csv?date=2026-11-01'))->assertOk()->getContent()));
        $this->assertSame('2026-11-01 01:30 EDT', $fallCsv[$firstFall->id]['starts_at']);
        $this->assertSame('2026-11-01 01:30 EST', $fallCsv[$firstFall->id]['return_due']);
        $this->assertSame('America/New_York', $fallCsv[$firstFall->id]['timezone']);
        $this->assertSame('2026-11-01 01:30 EST', $fallCsv[$secondFall->id]['starts_at']);
        $this->assertSame('2026-11-01 02:30 EST', $fallCsv[$secondFall->id]['return_due']);
        $this->assertArrayNotHasKey($spring->id, $fallCsv);

        $springCsv = (string) $this->get($this->tenantUrl('dock.test', '/staff/manifest.csv?date=2026-03-07'))->assertOk()->getContent();
        $this->assertStringContainsString('2026-03-07 22:00 EST', $springCsv);
        $this->assertStringContainsString('2026-03-08 04:00 EDT', $springCsv);
        $this->assertStringNotContainsString('Creek Only', $springCsv);

        $fallbackCsv = $this->byId($this->csvRows((string) $this->get($this->tenantUrl('dock.test', '/staff/manifest.csv?date=2026-06-16&location='.$fallback->location_id))->assertOk()->getContent()));
        $this->assertSame('2026-06-16 13:00 CDT', $fallbackCsv[$fallbackVisit->id]['starts_at']);
        $this->assertSame('2026-06-16 14:00 CDT', $fallbackCsv[$fallbackVisit->id]['return_due']);
        $this->assertSame('America/Chicago', $fallbackCsv[$fallbackVisit->id]['timezone']);

        $utcCsv = $this->byId($this->csvRows((string) $this->get($this->tenantUrl('dock.test', '/staff/manifest.csv?date=2026-06-16&location='.$utc->location_id))->assertOk()->getContent()));
        $this->assertSame('2026-06-16 18:00 UTC', $utcCsv[$utcVisit->id]['starts_at']);
        $this->assertSame('2026-06-16 20:00 UTC', $utcCsv[$utcVisit->id]['return_due']);
        $this->assertSame('UTC', $utcCsv[$utcVisit->id]['timezone']);

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $storedFirst = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($firstFall->id);
        $storedSecond = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($secondFall->id);
        $this->assertSame('2026-11-01 05:30:00', $storedFirst->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $storedFirst->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $storedSecond->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 07:30:00', $storedSecond->ends_at->utc()->format('Y-m-d H:i:s'));
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

    private function booking(Tenant $tenant, Service $service, string $startUtc, string $endUtc, string $customerName, string $email): Booking
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

    private function departure(Tenant $tenant, Service $service, string $startUtc, string $endUtc): Departure
    {
        app(TenantManager::class)->set($tenant);
        $tour = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $service->location_id,
            'name' => 'Sunrise Tour',
            'kind' => ServiceKind::Tour,
        ]);
        $departure = Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $tour->id,
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

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function csvRows(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<int, string|null>>  $rows
     * @return array<string, array<string, string>>
     */
    private function byId(array $rows): array
    {
        $header = array_shift($rows);
        $this->assertIsArray($header);
        $indexed = [];

        foreach ($rows as $row) {
            $assoc = [];

            foreach ($header as $index => $column) {
                if (! is_string($column)) {
                    continue;
                }

                $assoc[$column] = (string) ($row[$index] ?? '');
            }

            $indexed[$assoc['booking_id']] = $assoc;
        }

        return $indexed;
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertSeeInOrder(string $html, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = strpos($html, $needle, $offset);
            $this->assertNotFalse($position, 'Missing '.$needle.' after the previous marker.');
            $offset = $position + strlen($needle);
        }
    }
}
