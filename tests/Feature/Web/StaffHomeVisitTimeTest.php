<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffHomeVisitTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_home_names_each_visit_in_the_location_zone(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->location($alpha['tenant'], 'Harbor', 'America/New_York', '2026-01-01 00:00:00');
        $utc = $this->location($alpha['tenant'], 'Utc dock', 'UTC', '2026-01-02 00:00:00');
        $fallback = $this->location($alpha['tenant'], 'Fallback dock', 'Not/AZone', '2026-01-03 00:00:00');
        $otherDock = $this->location($beta['tenant'], 'Other dock', 'America/New_York', '2026-01-01 00:00:00');

        $span = $this->visit($alpha['tenant'], $harbor, 'Spring span', '2026-03-07 15:00:00', '2026-03-09 15:00:00');
        $jump = $this->visit($alpha['tenant'], $harbor, 'Jump paddle', '2026-03-08 06:30:00', '2026-03-08 07:30:00');
        $utcVisit = $this->visit($alpha['tenant'], $utc, 'Utc paddle', '2026-03-08 18:00:00', '2026-03-08 19:00:00');
        $fallbackVisit = $this->visit($alpha['tenant'], $fallback, 'Fallback paddle', '2026-03-08 18:00:00', '2026-03-08 19:00:00');
        $early = $this->visit($alpha['tenant'], $harbor, 'Early hour', '2026-11-01 05:30:00', '2026-11-01 05:45:00');
        $late = $this->visit($alpha['tenant'], $harbor, 'Late hour', '2026-11-01 06:30:00', '2026-11-01 06:45:00');
        $secret = $this->visit($beta['tenant'], $otherDock, 'Secret paddle', '2026-03-08 15:00:00', '2026-03-08 16:00:00');
        $jumpTour = $this->departure($alpha['tenant'], $harbor, 'Jump tour', '2026-03-08 06:30:00', '2026-03-08 07:30:00', 1);
        $short = $this->departure($alpha['tenant'], $harbor, 'Short sunset', '2026-03-08 22:00:00', '2026-03-09 00:00:00', 4);
        $this->party($short, '2026-03-08 22:00:00', '2026-03-09 00:00:00');
        $due = $this->checkout($alpha['tenant'], $harbor, 'Due kayak', '2026-03-08 14:00:00', '2026-03-09 15:00:00');
        $earlyDue = $this->checkout($alpha['tenant'], $harbor, 'Early due', '2026-11-01 04:30:00', '2026-11-01 05:30:00');
        $lateDue = $this->checkout($alpha['tenant'], $harbor, 'Late due', '2026-11-01 04:30:00', '2026-11-01 06:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);
        app(TenantManager::class)->forget();

        Carbon::setTestNow(Carbon::parse('2026-03-08 16:00:00', 'UTC'));
        $spring = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk();
        $springHtml = (string) $spring->getContent();

        $spanRow = $this->fragment($springHtml, 'data-booking', $span->id);
        $this->assertStringContainsString('Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT', $spanRow);
        $this->assertStringContainsString('until 11:00 AM EDT', $spanRow);
        $this->assertStringContainsString('Spring span', $spanRow);

        $jumpRow = $this->fragment($springHtml, 'data-booking', $jump->id);
        $this->assertStringContainsString('Sun, Mar 8, 1:30 AM EST – 3:30 AM EDT', $jumpRow);
        $this->assertStringContainsString('until 3:30 AM EDT', $jumpRow);

        $utcRow = $this->fragment($springHtml, 'data-booking', $utcVisit->id);
        $this->assertStringContainsString('Sun, Mar 8, 6:00 PM – 7:00 PM UTC', $utcRow);
        $this->assertStringContainsString('until 7:00 PM UTC', $utcRow);
        $this->assertStringNotContainsString('EDT', $utcRow);
        $this->assertStringNotContainsString('CDT', $utcRow);

        $fallbackRow = $this->fragment($springHtml, 'data-booking', $fallbackVisit->id);
        $this->assertStringContainsString('Sun, Mar 8, 1:00 PM – 2:00 PM CDT', $fallbackRow);
        $this->assertStringContainsString('until 2:00 PM CDT', $fallbackRow);
        $this->assertStringNotContainsString('Not/AZone', $fallbackRow);
        $this->assertStringNotContainsString('EST', $fallbackRow);

        $tour = $this->fragment($springHtml, 'data-departure', $jumpTour->id);
        $this->assertStringContainsString('Sun, Mar 8, 1:30 AM EST – 3:30 AM EDT', $tour);
        $this->assertStringContainsString('Jump tour', $tour);

        $below = $this->fragment($springHtml, 'data-below-minimum', $short->id);
        $this->assertStringContainsString('Sun, Mar 8, 6:00 PM – 8:00 PM EDT', $below);
        $this->assertStringContainsString('Short sunset', $below);
        $this->assertStringContainsString('1 of 4', $below);

        $dueRow = $this->fragment($springHtml, 'data-checked-out-row', $due->id);
        $this->assertStringContainsString('Due Mon, Mar 9, 11:00 AM EDT', $dueRow);
        $this->assertStringContainsString('Due kayak', $dueRow);

        $this->assertStringNotContainsString('Secret paddle', $springHtml);
        $this->assertStringNotContainsString('data-booking="'.$secret->id.'"', $springHtml);
        $this->assertStringNotContainsString('Early hour', $springHtml);
        $this->assertStringContainsString('@media (max-width: 390px)', $springHtml);
        $this->assertStringContainsString('.home-when', $springHtml);
        $this->assertStringContainsString('overflow-wrap: anywhere', $springHtml);

        Carbon::setTestNow(Carbon::parse('2026-11-01 05:00:00', 'UTC'));
        $fall = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk();
        $fallHtml = (string) $fall->getContent();

        $earlyRow = $this->fragment($fallHtml, 'data-booking', $early->id);
        $this->assertStringContainsString('Sun, Nov 1, 1:30 AM – 1:45 AM EDT', $earlyRow);
        $this->assertStringNotContainsString('EST', $earlyRow);

        $lateRow = $this->fragment($fallHtml, 'data-booking', $late->id);
        $this->assertStringContainsString('Sun, Nov 1, 1:30 AM – 1:45 AM EST', $lateRow);
        $this->assertStringNotContainsString('EDT', $lateRow);

        $earlyDueRow = $this->fragment($fallHtml, 'data-checked-out-row', $earlyDue->id);
        $this->assertStringContainsString('Due Sun, Nov 1, 1:30 AM EDT', $earlyDueRow);
        $lateDueRow = $this->fragment($fallHtml, 'data-checked-out-row', $lateDue->id);
        $this->assertStringContainsString('Due Sun, Nov 1, 1:30 AM EST', $lateDueRow);

        $this->assertStringNotContainsString('Secret paddle', $fallHtml);
        $this->assertStringNotContainsString('Spring span', $fallHtml);
        $this->assertStringNotContainsString('Jump tour', $fallHtml);

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $stored = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($span->id);
        $this->assertSame('2026-03-07 15:00:00', $stored->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $stored->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 05:30:00', $early->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $late->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
    }

    private function location(Tenant $tenant, string $name, string $timezone, string $createdAt): Location
    {
        app(TenantManager::class)->set($tenant);
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => $timezone,
        ]);
        $location->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $location;
    }

    private function visit(
        Tenant $tenant,
        Location $location,
        string $name,
        string $startsAt,
        string $endsAt,
    ): Booking {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 8,
            'waitlist_enabled' => false,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'participants' => 1,
            'payload_hash' => hash('sha256', $name.$startsAt),
        ]);
    }

    private function party(Departure $departure, string $startsAt, string $endsAt): Booking
    {
        app(TenantManager::class)->set($departure->tenant);

        return Booking::query()->create([
            'tenant_id' => $departure->tenant_id,
            'location_id' => $departure->location_id,
            'service_id' => $departure->service_id,
            'departure_id' => $departure->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'participants' => 1,
            'payload_hash' => hash('sha256', $departure->id.$startsAt),
        ]);
    }

    private function departure(
        Tenant $tenant,
        Location $location,
        string $name,
        string $startsAt,
        string $endsAt,
        int $minimum,
    ): Departure {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Tour,
            'min_participants' => $minimum,
            'max_participants' => 8,
            'waitlist_enabled' => false,
        ]);

        return Departure::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
    }

    private function checkout(Tenant $tenant, Location $location, string $name, string $checkedOutAt, string $dueAt): RentalCheckout
    {
        app(TenantManager::class)->set($tenant);
        $booking = $this->visit($tenant, $location, $name, $checkedOutAt, $dueAt);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
        ]);

        return RentalCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'checked_out_at' => $checkedOutAt,
            'due_at' => $dueAt,
        ]);
    }

    private function fragment(string $html, string $attribute, string $id): string
    {
        $matched = preg_match(
            '/<li\b[^>]*\b'.preg_quote($attribute, '/').'="'.preg_quote($id, '/').'"[^>]*>.*?<\/li>/s',
            $html,
            $match,
        );
        $this->assertSame(1, $matched);

        return $match[0];
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
