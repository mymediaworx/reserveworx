<?php

namespace Tests\Feature\Web;

use App\Enums\DepartureStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffReportTourTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_tour_rows_name_the_location_zone_including_both_fall_back_hours(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->tour($workspace['tenant'], 'America/New_York', 'Harbor');
        $utc = $this->tour($workspace['tenant'], 'UTC', 'Utc');
        $fallback = $this->tour($workspace['tenant'], 'Not/AZone', 'Fallback');
        $creek = $this->tour($other['tenant'], 'America/New_York', 'Creek');

        $spring = $this->departure($workspace['tenant'], $harbor, '2026-03-08 06:30:00', '2026-03-08 08:30:00');
        $firstFall = $this->departure($workspace['tenant'], $harbor, '2026-11-01 05:30:00', '2026-11-01 06:30:00');
        $secondFall = $this->departure($workspace['tenant'], $harbor, '2026-11-01 06:30:00', '2026-11-01 07:30:00');
        $utcDeparture = $this->departure($workspace['tenant'], $utc, '2026-06-16 18:00:00', '2026-06-16 19:00:00');
        $fallbackDeparture = $this->departure($workspace['tenant'], $fallback, '2026-06-16 04:30:00', '2026-06-16 05:30:00');
        $this->departure($other['tenant'], $creek, '2026-03-08 06:30:00', '2026-03-08 08:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $springPage = $this->get($this->tenantUrl('dock.test', '/staff/reports?from=2026-03-08&to=2026-03-08'));
        $springPage->assertOk()
            ->assertSee('Tour capacity and attendance')
            ->assertSee('Harbor sunrise')
            ->assertSee('Sun, Mar 8, 1:30 AM EST – 4:30 AM EDT')
            ->assertSee('data-tour="'.$spring->id.'"', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.reports-page .when { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }', false)
            ->assertDontSee('Creek sunrise')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('12:30 AM CST');

        $fallPage = (string) $this->get($this->tenantUrl('dock.test', '/staff/reports?from=2026-11-01&to=2026-11-01'))
            ->assertOk()
            ->assertSee('Sun, Nov 1, 1:30 AM EDT – 1:30 AM EST')
            ->assertSee('Sun, Nov 1, 1:30 AM – 2:30 AM EST')
            ->assertSee('data-tour="'.$firstFall->id.'"', false)
            ->assertSee('data-tour="'.$secondFall->id.'"', false)
            ->assertDontSee('Sun, Mar 8')
            ->getContent();
        $this->assertSeeInOrder($fallPage, [
            'Sun, Nov 1, 1:30 AM EDT – 1:30 AM EST',
            'Sun, Nov 1, 1:30 AM – 2:30 AM EST',
        ]);

        $this->get($this->tenantUrl('dock.test', '/staff/reports?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertSee('Utc sunrise')
            ->assertSee('Tue, Jun 16, 6:00 PM – 7:00 PM UTC')
            ->assertSee('data-tour="'.$utcDeparture->id.'"', false)
            ->assertDontSee('EDT')
            ->assertDontSee('CDT')
            ->assertDontSee('Fallback sunrise');

        $this->get($this->tenantUrl('dock.test', '/staff/reports?from=2026-06-15&to=2026-06-15'))
            ->assertOk()
            ->assertSee('Fallback sunrise')
            ->assertSee('Mon, Jun 15, 11:30 PM – Tue, Jun 16, 12:30 AM CDT')
            ->assertSee('data-tour="'.$fallbackDeparture->id.'"', false)
            ->assertDontSee('Not/AZone')
            ->assertDontSee('4:30 AM UTC')
            ->assertDontSee('Utc sunrise');

        $fallCsv = $this->csvTour((string) $this->get($this->tenantUrl('dock.test', '/staff/reports.csv?from=2026-11-01&to=2026-11-01'))->assertOk()->getContent());
        $this->assertSame('2026-11-01', $fallCsv[$firstFall->id]['date']);
        $this->assertSame('Sun, Nov 1, 1:30 AM EDT – 1:30 AM EST', $fallCsv[$firstFall->id]['starts_at']);
        $this->assertSame('Sun, Nov 1, 1:30 AM – 2:30 AM EST', $fallCsv[$secondFall->id]['starts_at']);
        $this->assertArrayNotHasKey($spring->id, $fallCsv);
        $this->assertArrayNotHasKey($utcDeparture->id, $fallCsv);

        $this->actingAsWorkspace($workspace);
        $api = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/tours?from=2026-11-01&to=2026-11-01'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($api);
        $byId = [];

        foreach ($api as $row) {
            $this->assertIsArray($row);
            $byId[$row['departure_id']] = $row;
        }

        $this->assertSame('America/New_York', $byId[$firstFall->id]['timezone']);
        $this->assertSame('2026-11-01T01:30:00', $byId[$firstFall->id]['starts_at']);
        $this->assertSame('2026-11-01T01:30:00', $byId[$secondFall->id]['starts_at']);
        $this->assertSame('Sun, Nov 1, 1:30 AM EDT – 1:30 AM EST', $byId[$firstFall->id]['when']);
        $this->assertSame('Sun, Nov 1, 1:30 AM – 2:30 AM EST', $byId[$secondFall->id]['when']);
        $this->assertNotSame($byId[$firstFall->id]['when'], $byId[$secondFall->id]['when']);

        $fallbackApi = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/tours?from=2026-06-15&to=2026-06-15'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($fallbackApi);
        $this->assertCount(1, $fallbackApi);
        $this->assertIsArray($fallbackApi[0]);
        $this->assertSame($fallbackDeparture->id, $fallbackApi[0]['departure_id']);
        $this->assertSame('America/Chicago', $fallbackApi[0]['timezone']);
        $this->assertSame('Mon, Jun 15, 11:30 PM – Tue, Jun 16, 12:30 AM CDT', $fallbackApi[0]['when']);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/tours?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonPath('data.0.departure_id', $utcDeparture->id)
            ->assertJsonPath('data.0.timezone', 'UTC')
            ->assertJsonPath('data.0.when', 'Tue, Jun 16, 6:00 PM – 7:00 PM UTC')
            ->assertJsonMissing(['departure_id' => $fallbackDeparture->id]);

        $export = (string) $this->get($this->tenantUrl('dock.test', '/api/v1/reports/tours/export?from=2026-11-01&to=2026-11-01'))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('Sun, Nov 1, 1:30 AM EDT – 1:30 AM EST', $export);
        $this->assertStringContainsString('Sun, Nov 1, 1:30 AM – 2:30 AM EST', $export);
        $this->assertStringNotContainsString('Creek sunrise', $export);

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $stored = Departure::withoutGlobalScope(TenantScope::class)->findOrFail($firstFall->id);
        $this->assertSame('2026-11-01 05:30:00', $stored->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $stored->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    private function tour(Tenant $tenant, string $timezone, string $name): Service
    {
        app(TenantManager::class)->set($tenant);
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => $timezone,
            'name' => $name.' Dock',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name.' sunrise',
            'kind' => ServiceKind::Tour,
            'max_participants' => 8,
        ]);
        app(TenantManager::class)->forget();

        return $service;
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

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function csvTour(string $csv): array
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
        $header = array_shift($rows);
        $this->assertIsArray($header);
        $indexed = [];

        foreach ($rows as $row) {
            if (($row[0] ?? '') !== 'tour') {
                continue;
            }

            $assoc = [];

            foreach ($header as $index => $column) {
                if (! is_string($column)) {
                    continue;
                }

                $assoc[$column] = (string) ($row[$index] ?? '');
            }

            $indexed[$assoc['departure_id']] = $assoc;
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
