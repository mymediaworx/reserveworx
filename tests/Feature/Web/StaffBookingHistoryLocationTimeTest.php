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

class StaffBookingHistoryLocationTimeTest extends TestCase
{
    use AssertsZoneAbbreviations, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_booking_history_names_the_location_zone_including_both_daylight_saving_changes(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $other['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->service($workspace['tenant'], 'America/New_York', 'Harbor Kayak');
        $utc = $this->service($workspace['tenant'], 'UTC', 'Utc Kayak');
        $fallback = $this->service($workspace['tenant'], 'Not/AZone', 'Fallback Kayak');
        $creek = $this->service($other['tenant'], 'America/New_York', 'Creek Kayak');

        $harborVisit = $this->booking($workspace['tenant'], $harbor, '2026-07-04 14:00:00', '2026-07-04 15:00:00', 'harbor@example.com');
        $utcVisit = $this->booking($workspace['tenant'], $utc, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'utc@example.com');
        $fallbackVisit = $this->booking($workspace['tenant'], $fallback, '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'fallback@example.com');
        $creekVisit = $this->booking($other['tenant'], $creek, '2026-01-15 16:00:00', '2026-01-15 17:00:00', 'creek@example.com');

        $this->history($harborVisit, 'booking.rescheduled', '2026-03-08 06:30:00');
        $this->history($harborVisit, 'booking.rescheduled', '2026-03-08 07:30:00');
        $this->history($harborVisit, 'booking.confirmed', '2026-06-16 18:00:00');
        $this->history($harborVisit, 'booking.cancelled', '2026-11-01 05:30:00');
        $this->history($harborVisit, 'booking.cancelled', '2026-11-01 06:30:00');
        $this->history($utcVisit, 'booking.confirmed', '2026-06-16 18:00:00');
        $this->history($fallbackVisit, 'booking.confirmed', '2026-06-16 18:00:00');
        $this->history($creekVisit, 'booking.guide_assigned', '2026-01-15 16:00:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $harborPage = (string) $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$harborVisit->id))
            ->assertOk()
            ->assertSee('History')
            ->assertSee('data-history-when', false)
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('Sun, Mar 8, 1:30 AM EST')
            ->assertSee('Sun, Mar 8, 3:30 AM EDT')
            ->assertSee('Tue, Jun 16, 2:00 PM EDT')
            ->assertSee('.history time[data-history-when] { max-width: 100%; overflow-wrap: anywhere; }', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('Tue, Jun 16, 1:00 PM CDT')
            ->assertDontSee('Tue, Jun 16, 6:00 PM UTC')
            ->assertDontSee('Guide assigned')
            ->assertDontSee('Thu, Jan 15, 11:00 AM EST')
            ->assertDontSee('America/Chicago')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('Creek Kayak')
            ->getContent();
        $this->assertSeeInOrder($harborPage, [
            'Sun, Nov 1, 1:30 AM EST',
            'Sun, Nov 1, 1:30 AM EDT',
            'Tue, Jun 16, 2:00 PM EDT',
            'Sun, Mar 8, 3:30 AM EDT',
            'Sun, Mar 8, 1:30 AM EST',
        ]);

        $utcPage = $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$utcVisit->id))
            ->assertOk()
            ->assertSee('Utc Kayak')
            ->assertSee('Tue, Jun 16, 6:00 PM UTC')
            ->assertDontSee('Guide assigned')
            ->assertDontSee('1:00 PM CDT')
            ->assertDontSee('2:00 PM EDT');
        $this->assertOmitsZoneAbbreviations($utcPage, 'EDT', 'CDT', 'EST');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$fallbackVisit->id))
            ->assertOk()
            ->assertSee('Fallback Kayak')
            ->assertSee('Tue, Jun 16, 1:00 PM CDT')
            ->assertDontSee('2:00 PM EDT')
            ->assertDontSee('6:00 PM UTC')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('Guide assigned');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$creekVisit->id))
            ->assertNotFound()
            ->assertDontSee('Guide assigned')
            ->assertDontSee('Creek Kayak');

        $this->get($this->tenantUrl('creek.test', '/staff/bookings/'.$harborVisit->id))
            ->assertForbidden()
            ->assertDontSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertDontSee('Harbor Kayak');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $stored = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('subject_id', $harborVisit->id)
            ->orderBy('created_at')
            ->pluck('created_at');
        $this->assertSame([
            '2026-03-08 06:30:00',
            '2026-03-08 07:30:00',
            '2026-06-16 18:00:00',
            '2026-11-01 05:30:00',
            '2026-11-01 06:30:00',
        ], $stored->map(fn ($at): string => CarbonImmutable::parse($at)->utc()->format('Y-m-d H:i:s'))->all());
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

    private function history(Booking $booking, string $action, string $createdAtUtc): void
    {
        AuditLog::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $booking->tenant_id,
            'actor_user_id' => null,
            'action' => $action,
            'subject_type' => Booking::class,
            'subject_id' => $booking->id,
            'properties' => [],
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => CarbonImmutable::parse($createdAtUtc, 'UTC'),
        ]);
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
            $found = strpos($html, $needle, $offset);
            $this->assertNotFalse($found, $needle.' was not found after offset '.$offset);
            $offset = $found + strlen($needle);
        }
    }
}
