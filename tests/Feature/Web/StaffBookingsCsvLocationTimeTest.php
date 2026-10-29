<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Bookings;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
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
use App\Support\Display;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffBookingsCsvLocationTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_bookings_csv_keeps_both_fall_back_hours(): void
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

        $firstFall = $this->booking($workspace['tenant'], $harbor, '2026-11-01 05:30:00', '2026-11-01 06:30:00', 'First Fall', 'first@example.com');
        $secondFall = $this->booking($workspace['tenant'], $harbor, '2026-11-01 06:30:00', '2026-11-01 07:30:00', 'Second Fall', 'second@example.com');
        $spring = $this->booking($workspace['tenant'], $harbor, '2026-03-08 07:30:00', '2026-03-08 08:30:00', 'Spring Guest', 'spring@example.com');
        $utcVisit = $this->booking($workspace['tenant'], $utc, '2026-06-16 18:00:00', '2026-06-16 19:00:00', 'Utc Guest', 'utc@example.com');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, '2026-06-16 18:00:00', '2026-06-16 19:00:00', 'Fallback Guest', 'fallback@example.com');
        $creekVisit = $this->booking($other['tenant'], $creek, '2026-11-01 05:30:00', '2026-11-01 06:30:00', 'Creek Only', 'creek@example.com');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $this->get($this->tenantUrl('dock.test', '/staff/bookings?when=all'))
            ->assertOk()
            ->assertSee(Display::dateTimeWithZone($firstFall->starts_at, 'America/New_York'), false)
            ->assertSee(Display::dateTimeWithZone($secondFall->starts_at, 'America/New_York'), false)
            ->assertSee(Display::dateTimeWithZone($spring->starts_at, 'America/New_York'), false)
            ->assertSee(Display::dateTimeWithZone($utcVisit->starts_at, 'UTC'), false)
            ->assertSee(Display::dateTimeWithZone($fallbackVisit->starts_at, 'America/Chicago'), false)
            ->assertDontSee('Creek Only')
            ->assertDontSee('Not/AZone')
            ->assertSee('@media (max-width: 390px)', false);

        app(TenantManager::class)->set($workspace['tenant']);

        $test = Livewire::test(Bookings::class)
            ->set('selected', [
                $firstFall->id,
                $secondFall->id,
                $spring->id,
                $utcVisit->id,
                $fallbackVisit->id,
                $creekVisit->id,
            ])
            ->call('exportSelected')
            ->assertFileDownloaded('bookings.csv', null, 'text/csv; charset=UTF-8');
        $raw = base64_decode((string) data_get($test->effects, 'download.content'), true);
        $this->assertIsString($raw);
        $csv = $this->byId($raw);

        $this->assertSame('2026-11-01 01:30 EDT', $csv[$firstFall->id]);
        $this->assertSame('2026-11-01 01:30 EST', $csv[$secondFall->id]);
        $this->assertNotSame($csv[$firstFall->id], $csv[$secondFall->id]);
        $this->assertSame('2026-03-08 03:30 EDT', $csv[$spring->id]);
        $this->assertSame('2026-06-16 18:00 UTC', $csv[$utcVisit->id]);
        $this->assertSame('2026-06-16 13:00 CDT', $csv[$fallbackVisit->id]);
        $this->assertArrayNotHasKey($creekVisit->id, $csv);
        $this->assertStringNotContainsString('Creek Only', $raw);
        $this->assertStringNotContainsString('Not/AZone', $raw);

        $this->assertSame('2026-11-01 05:30:00', $firstFall->fresh()?->starts_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $secondFall->fresh()?->starts_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-08 07:30:00', $spring->fresh()?->starts_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));
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

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }

    /**
     * @return array<string, string>
     */
    private function byId(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);
        $header = fgetcsv($handle);
        $this->assertIsArray($header);
        $id = array_search('booking_id', $header, true);
        $start = array_search('starts_at', $header, true);
        $this->assertIsInt($id);
        $this->assertIsInt($start);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $bookingId = $row[$id] ?? '';
            $clock = $row[$start] ?? '';

            if (is_string($bookingId) && $bookingId !== '' && is_string($clock)) {
                $rows[$bookingId] = $clock;
            }
        }

        fclose($handle);

        return $rows;
    }
}
