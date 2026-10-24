<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ReportSummary;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Reports\PortfolioReport;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PortfolioTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_rollup_math_filter_and_export_include_only_owned_businesses(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-15 15:00:00', 'UTC'));

        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $beta = $this->workspace('beta.test', 'owner', ['name' => 'Beta Boats']);
        $gamma = $this->workspace('gamma.test', 'owner', ['name' => 'Gamma Gear']);
        Membership::factory()->role('owner')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);
        Membership::factory()->role('staff')->create([
            'tenant_id' => $gamma['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);

        $alphaShop = $this->shop($alpha['tenant']);
        $betaShop = $this->shop($beta['tenant']);
        $gammaShop = $this->shop($gamma['tenant']);

        $ada = Customer::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Ada Lovelace',
            'email' => 'guest@example.com',
        ]);
        $bea = Customer::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Bea Returning',
            'email' => 'bea@example.com',
        ]);
        $cam = Customer::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Cam Stone',
            'email' => 'guest@example.com',
        ]);
        $secret = Customer::factory()->create([
            'tenant_id' => $gamma['tenant']->id,
            'name' => 'Secret Gamma',
            'email' => 'secret@example.com',
        ]);

        $this->visit($alphaShop, $ada, BookingStatus::Confirmed, '2026-10-15 15:00:00', ['services_cents' => 10000], 300, 50, 200);
        $this->visit($alphaShop, null, BookingStatus::Cancelled, '2026-10-16 15:00:00', ['services_cents' => 8000], 100, 0, 400);
        $this->visit($alphaShop, $bea, BookingStatus::Confirmed, '2026-09-01 15:00:00', ['services_cents' => 1000], 0, 0, 0);
        $this->visit($alphaShop, $bea, BookingStatus::NoShow, '2026-10-17 15:00:00', ['basis_cents' => 2500], 75, 0, 0);
        $this->visit($alphaShop, $ada, BookingStatus::Confirmed, '2026-10-01 03:00:00', ['services_cents' => 7777], 0, 0, 0);
        $this->visit($alphaShop, $ada, BookingStatus::Held, '2026-10-18 15:00:00', ['services_cents' => 1111], 0, 0, 0);
        $this->visit($betaShop, $cam, BookingStatus::Confirmed, '2026-10-10 15:00:00', ['services_cents' => 5000], 150, 0, 0);
        $this->visit($gammaShop, $secret, BookingStatus::Confirmed, '2026-10-12 15:00:00', ['services_cents' => 99999], 0, 0, 0);
        $this->visit($alphaShop, $ada, BookingStatus::Confirmed, '2026-11-18 15:00:00', ['services_cents' => 50], 0, 0, 0);
        $this->visit($alphaShop, $ada, BookingStatus::Confirmed, '2026-11-23 15:00:00', ['services_cents' => 60], 0, 0, 0);

        ReportSummary::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $alphaShop['location']->id,
            'summary_date' => '2026-10-15',
            'available_resource_minutes' => 120,
            'allocated_resource_minutes' => 30,
            'bookings_created' => 1,
            'bookings_converted' => 1,
            'gross_cents' => 10000,
        ]);
        ReportSummary::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $alphaShop['location']->id,
            'summary_date' => '2026-10-16',
            'available_resource_minutes' => 80,
            'allocated_resource_minutes' => 40,
            'bookings_created' => 0,
            'bookings_converted' => 0,
            'gross_cents' => 0,
        ]);
        ReportSummary::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $alphaShop['location']->id,
            'summary_date' => '2026-11-01',
            'available_resource_minutes' => 1000,
            'allocated_resource_minutes' => 1000,
            'bookings_created' => 0,
            'bookings_converted' => 0,
            'gross_cents' => 0,
        ]);
        ReportSummary::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaShop['location']->id,
            'summary_date' => '2026-10-10',
            'available_resource_minutes' => 100,
            'allocated_resource_minutes' => 50,
            'bookings_created' => 1,
            'bookings_converted' => 1,
            'gross_cents' => 5000,
        ]);
        ReportSummary::query()->create([
            'tenant_id' => $gamma['tenant']->id,
            'location_id' => $gammaShop['location']->id,
            'summary_date' => '2026-10-12',
            'available_resource_minutes' => 500,
            'allocated_resource_minutes' => 500,
            'bookings_created' => 1,
            'bookings_converted' => 1,
            'gross_cents' => 99999,
        ]);

        $october = '/staff/portfolio?preset=custom&from=2026-10-01&to=2026-10-31';

        app(TenantManager::class)->set($beta['tenant']);
        $report = app(PortfolioReport::class)->build(
            collect([$alpha['tenant'], $beta['tenant']]),
            '2026-10-01',
            '2026-10-31',
        );
        app(TenantManager::class)->forget();

        $this->assertSame(3, $report['totals']['bookings']);
        $this->assertSame(2, $report['totals']['confirmed']);
        $this->assertSame(1, $report['totals']['cancelled']);
        $this->assertSame(1, $report['totals']['no_shows']);
        $this->assertSame(2500, $report['totals']['cancellation_bps']);
        $this->assertSame(2500, $report['totals']['no_show_bps']);
        $this->assertSame(17500, $report['totals']['gross_cents']);
        $this->assertSame(625, $report['totals']['platform_fees_cents']);
        $this->assertSame(50, $report['totals']['fee_refunded_cents']);
        $this->assertSame(600, $report['totals']['refunds_cents']);
        $this->assertSame(16325, $report['totals']['net_cents']);
        $this->assertSame(1, $report['totals']['new_customers']);
        $this->assertSame(2, $report['totals']['returning_customers']);
        $this->assertSame(1, $report['totals']['upcoming']);
        $this->assertSame(4000, $report['totals']['utilization_bps']);
        $this->assertSame(10000, $report['trend'][14]['gross_cents']);

        app(TenantManager::class)->set($beta['tenant']);
        $onlyAlpha = app(PortfolioReport::class)->build(new Collection([$alpha['tenant']]), '2026-10-01', '2026-10-31');
        app(TenantManager::class)->forget();
        $this->assertSame(12500, $onlyAlpha['totals']['gross_cents']);
        $this->assertSame(3333, $onlyAlpha['totals']['cancellation_bps']);
        $this->assertSame(3500, $onlyAlpha['totals']['utilization_bps']);

        $page = $this->actingAs($alpha['user'])->get($this->tenantUrl('alpha.test', $october));
        $page->assertOk()
            ->assertSee('All businesses')
            ->assertSee('Alpha Kayaks')
            ->assertSee('Beta Boats')
            ->assertSee('$175.00')
            ->assertSee('$125.00')
            ->assertSee('$50.00')
            ->assertSee('25.0%')
            ->assertSee('40.0%')
            ->assertSee('value="all" selected', false)
            ->assertDontSee('$999.99')
            ->assertDontSee('Secret Gamma');

        $filtered = $october.'&business='.$beta['tenant']->id;
        $this->get($this->tenantUrl('alpha.test', $filtered))
            ->assertOk()
            ->assertSee('value="'.$beta['tenant']->id.'" selected', false)
            ->assertSee('$50.00')
            ->assertSee('50.0%')
            ->assertDontSee('$125.00')
            ->assertDontSee('$175.00')
            ->assertDontSee('$999.99')
            ->assertDontSee('35.0%')
            ->assertDontSee('40.0%');

        $this->get($this->tenantUrl('alpha.test', $october.'&business='.$gamma['tenant']->id))
            ->assertNotFound()
            ->assertDontSee('$999.99');

        $this->get($this->tenantUrl('alpha.test', $october.'&business=not-a-workspace'))
            ->assertNotFound();

        $csv = $this->get($this->tenantUrl('alpha.test', '/staff/portfolio.csv?preset=custom&from=2026-10-01&to=2026-10-31'));
        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $csv->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString('Alpha Kayaks', $body);
        $this->assertStringContainsString('Beta Boats', $body);
        $this->assertStringContainsString('17500', $body);
        $this->assertStringContainsString('12500', $body);
        $this->assertStringNotContainsString('99999', $body);

        $betaCsv = $this->get($this->tenantUrl('alpha.test', '/staff/portfolio.csv?preset=custom&from=2026-10-01&to=2026-10-31&business='.$beta['tenant']->id));
        $betaBody = $betaCsv->getContent();
        $this->assertIsString($betaBody);
        $this->assertStringContainsString('5000', $betaBody);
        $this->assertStringContainsString('Beta Boats', $betaBody);
        $this->assertStringNotContainsString('12500', $betaBody);
        $this->assertStringNotContainsString('99999', $betaBody);
        $this->assertStringNotContainsString('Alpha Kayaks', $betaBody);

        $this->get($this->tenantUrl('alpha.test', '/staff/portfolio'))
            ->assertOk()
            ->assertSee('2026-10-17')
            ->assertSee('2026-11-15')
            ->assertSee('$25.00')
            ->assertSee('100.0%')
            ->assertDontSee('$175.00');

        $this->get($this->tenantUrl('alpha.test', '/staff/portfolio?preset=nope'))->assertStatus(422);
        $this->get($this->tenantUrl('alpha.test', '/staff/portfolio?preset=custom&from=2026-01-01&to=2027-02-01'))->assertStatus(422);

        $staff = $this->workspace('staff.test', 'staff');
        $this->actingAs($staff['user'])
            ->get($this->tenantUrl('staff.test', '/staff/portfolio'))
            ->assertForbidden();

        $solo = $this->workspace('solo.test', 'owner', ['name' => 'Solo Dock']);
        $this->actingAs($solo['user'])
            ->get($this->tenantUrl('solo.test', '/staff/portfolio'))
            ->assertOk()
            ->assertSee('Solo Dock')
            ->assertSee('All businesses');
    }

    /**
     * @return array{tenant: Tenant, location: Location, service: Service}
     */
    private function shop(Tenant $tenant): array
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'America/New_York',
            'name' => $tenant->name.' dock',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Rental',
            'kind' => 'rental',
        ]);

        return ['tenant' => $tenant, 'location' => $location, 'service' => $service];
    }

    /**
     * @param  array{tenant: Tenant, location: Location, service: Service}  $shop
     * @param  array<string, int>  $payload
     */
    private function visit(
        array $shop,
        ?Customer $customer,
        BookingStatus $status,
        string $startsAt,
        array $payload,
        int $feeCents,
        int $feeRefundedCents,
        int $refundCents,
    ): Booking {
        $booking = Booking::query()->create([
            'tenant_id' => $shop['tenant']->id,
            'location_id' => $shop['location']->id,
            'service_id' => $shop['service']->id,
            'customer_id' => $customer?->id,
            'status' => $status,
            'payment_status' => 'paid',
            'operational_status' => 'not_arrived',
            'starts_at' => $startsAt,
            'ends_at' => CarbonImmutable::parse($startsAt, 'UTC')->addHour(),
            'participants' => 2,
            'payload_hash' => str_repeat('b', 64),
        ]);

        if ($payload !== []) {
            PricingSnapshot::query()->create([
                'tenant_id' => $shop['tenant']->id,
                'booking_id' => $booking->id,
                'currency' => 'USD',
                'payload' => $payload,
                'rules_version' => 'test',
            ]);
        }

        if ($feeCents > 0 || $feeRefundedCents > 0) {
            PlatformFee::query()->create([
                'tenant_id' => $shop['tenant']->id,
                'booking_id' => $booking->id,
                'amount_cents' => $feeCents,
                'refunded_cents' => $feeRefundedCents,
                'channel' => 'direct',
            ]);
        }

        if ($refundCents > 0) {
            Refund::query()->create([
                'tenant_id' => $shop['tenant']->id,
                'booking_id' => $booking->id,
                'reason' => 'customer_cancelled_partial_refund',
                'rules_version' => '7.7',
                'customer_refund_cents' => $refundCents,
                'customer_fee_refund_cents' => 0,
                'application_fee_refund_cents' => 0,
                'refund_subtotal_cents' => $refundCents,
                'refund_tax_cents' => 0,
            ]);
        }

        return $booking;
    }
}
