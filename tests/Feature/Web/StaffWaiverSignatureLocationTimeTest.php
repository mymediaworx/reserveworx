<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
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
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsZoneAbbreviations;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffWaiverSignatureLocationTimeTest extends TestCase
{
    use AssertsZoneAbbreviations, BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_signed_waivers_name_both_fall_back_hours_and_keep_utc(): void
    {
        $workspace = $this->workspace('dock.test', 'staff', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'staff', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->rentalShop($workspace['tenant'], 4, 'America/New_York');
        $utc = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $fallback = $this->rentalShop($workspace['tenant'], 4, 'Not/AZone');
        $creek = $this->rentalShop($other['tenant'], 4, 'America/New_York');

        $booking = $this->booking($workspace['tenant'], $harbor['location'], $harbor['service'], 'Harbor Guest', 'harbor@example.com');
        $utcBooking = $this->booking($workspace['tenant'], $utc['location'], $utc['service'], 'Utc Guest', 'utc@example.com');
        $fallbackBooking = $this->booking($workspace['tenant'], $fallback['location'], $fallback['service'], 'Fallback Guest', 'fallback@example.com');
        $creekBooking = $this->booking($other['tenant'], $creek['location'], $creek['service'], 'Creek Guest', 'creek@example.com');

        $early = $this->signature($workspace['tenant'], $booking, 'Early Signer', '2026-11-01 05:30:00');
        $late = $this->signature($workspace['tenant'], $booking, 'Late Signer', '2026-11-01 06:30:00');
        $spring = $this->signature($workspace['tenant'], $booking, 'Spring Signer', '2026-03-08 07:30:00');
        $utcSignature = $this->signature($workspace['tenant'], $utcBooking, 'Utc Signer', '2026-06-16 18:00:00');
        $fallbackSignature = $this->signature($workspace['tenant'], $fallbackBooking, 'Fallback Signer', '2026-06-16 18:00:00');
        $creekSignature = $this->signature($other['tenant'], $creekBooking, 'Creek Signer', '2026-11-01 05:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);
        $signatures = $this->rows(WaiverSignature::class);

        $this->actingAs($workspace['user']);

        $page = (string) $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$booking->id))
            ->assertOk()
            ->assertSee('Signed waivers')
            ->assertSee('Early Signer')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Late Signer')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('Spring Signer')
            ->assertSee('Sun, Mar 8, 3:30 AM EDT')
            ->assertSee('data-signed-at', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.waiver-copies [data-signed-at] { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }', false)
            ->assertDontSee('Creek Signer')
            ->assertDontSee('203.0.113.44')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('America/Chicago')
            ->getContent();
        $this->assertSeeInOrder($page, [
            'Sun, Mar 8, 3:30 AM EDT',
            'Sun, Nov 1, 1:30 AM EDT',
            'Sun, Nov 1, 1:30 AM EST',
        ]);

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$creekBooking->id))->assertNotFound();

        $utcPage = $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$utcBooking->id))
            ->assertOk()
            ->assertSee('Utc Signer')
            ->assertSee('Tue, Jun 16, 6:00 PM UTC')
            ->assertDontSee('Creek Signer');
        $this->assertOmitsZoneAbbreviations($utcPage, 'EDT', 'CDT');

        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$fallbackBooking->id))
            ->assertOk()
            ->assertSee('Fallback Signer')
            ->assertSee('Tue, Jun 16, 1:00 PM CDT')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('Tue, Jun 16, 2:00 PM EDT')
            ->assertDontSee('Creek Signer');

        $this->actingAs($other['user']);
        $this->get($this->tenantUrl('creek.test', '/staff/bookings/'.$creekBooking->id))
            ->assertOk()
            ->assertSee('Creek Signer')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertDontSee('Early Signer')
            ->assertDontSee('Harbor Guest');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('2026-11-01 05:30:00', $this->stamp($early));
        $this->assertSame('2026-11-01 06:30:00', $this->stamp($late));
        $this->assertSame('2026-03-08 07:30:00', $this->stamp($spring));
        $this->assertSame('2026-06-16 18:00:00', $this->stamp($utcSignature));
        $this->assertSame('2026-06-16 18:00:00', $this->stamp($fallbackSignature));
        app(TenantManager::class)->set($other['tenant']);
        $this->assertSame('2026-11-01 05:30:00', $this->stamp($creekSignature));

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));
        $this->assertSame($signatures, $this->rows(WaiverSignature::class));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    private function booking(Tenant $tenant, Location $location, Service $service, string $name, string $email): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 2,
            'payload_hash' => hash('sha256', $email),
        ]);
    }

    private function signature(Tenant $tenant, Booking $booking, string $name, string $signedAt): WaiverSignature
    {
        app(TenantManager::class)->set($tenant);
        $body = 'I accept the risks of this rental.';
        $version = WaiverVersion::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Rental waiver', 'version' => 1],
            ['body' => $body, 'content_hash' => hash('sha256', $body)],
        );

        return WaiverSignature::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'waiver_version_id' => $version->id,
            'participant_name' => $name,
            'is_minor' => false,
            'guardian_name' => null,
            'ip_address' => '203.0.113.44',
            'user_agent' => 'ReserveWorxTest',
            'content_hash' => $version->content_hash,
            'signed_at' => CarbonImmutable::parse($signedAt, 'UTC'),
        ]);
    }

    private function stamp(WaiverSignature $signature): string
    {
        return CarbonImmutable::instance(WaiverSignature::query()->findOrFail($signature->id)->signed_at)
            ->utc()
            ->format('Y-m-d H:i:s');
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
