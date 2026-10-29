<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResourceStatus;
use App\Enums\ServiceKind;
use App\Models\Allocation;
use App\Models\Asset;
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
use App\Models\ResourceCategory;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffRetirementOfferLocationTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_retired_item_names_each_offer_in_the_location_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-01 12:00:00', 'UTC'));

        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->place($workspace['tenant'], 'America/New_York', 'Harbor');
        $utc = $this->place($workspace['tenant'], 'UTC', 'Utc');
        $fallback = $this->place($workspace['tenant'], 'Not/AZone', 'Fallback');
        $creek = $this->place($other['tenant'], 'America/New_York', 'Creek');

        $early = $this->offer($workspace['tenant'], $harbor, 'Early Fall', '2026-11-01 05:30:00');
        $late = $this->offer($workspace['tenant'], $harbor, 'Late Fall', '2026-11-01 06:30:00');
        $springBefore = $this->offer($workspace['tenant'], $harbor, 'Spring Before', '2026-03-08 06:30:00');
        $springAfter = $this->offer($workspace['tenant'], $harbor, 'Spring After', '2026-03-08 07:30:00');
        $utcVisit = $this->offer($workspace['tenant'], $utc, 'Utc Guest', '2026-06-16 15:00:00');
        $fallbackVisit = $this->offer($workspace['tenant'], $fallback, 'Fallback Guest', '2026-07-04 20:00:00');
        $past = $this->offer($workspace['tenant'], $harbor, 'Already Started', '2026-02-01 15:00:00');
        $held = $this->offer($workspace['tenant'], $harbor, 'Still Held', '2026-11-01 16:00:00', BookingStatus::Held);
        $creekVisit = $this->offer($other['tenant'], $creek, 'Creek Guest', '2026-11-01 05:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $this->get($this->tenantUrl('dock.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Early Fall')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Late Fall')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('Spring Before')
            ->assertSee('Sun, Mar 8, 1:30 AM EST')
            ->assertSee('Spring After')
            ->assertSee('Sun, Mar 8, 3:30 AM EDT')
            ->assertSee('Utc Guest')
            ->assertSee('Tue, Jun 16, 3:00 PM UTC')
            ->assertSee('Fallback Guest')
            ->assertSee('Sat, Jul 4, 3:00 PM CDT')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.resources-page .retire-offers', false)
            ->assertDontSee('1:30 AM</a>')
            ->assertDontSee('Already Started')
            ->assertDontSee('Still Held')
            ->assertDontSee('Creek Guest')
            ->assertDontSee('3:00 PM CST')
            ->assertDontSee('4:00 PM EDT')
            ->assertDontSee('Tue, Jun 16, 11:00 AM EDT')
            ->assertDontSee('Sat, Jul 4, 8:00 PM UTC')
            ->assertDontSee('2:30 AM')
            ->assertDontSee('America/Chicago')
            ->assertDontSee('America/New_York')
            ->assertDontSee('Not/AZone');

        $this->actingAs($other['user']);

        $this->get($this->tenantUrl('creek.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Creek Guest')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertDontSee('Early Fall')
            ->assertDontSee('Fallback Guest')
            ->assertDontSee('Utc Guest');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $this->assertSame('2026-11-01 05:30:00', $this->storedStart($early));
        $this->assertSame('2026-11-01 06:30:00', $this->storedStart($late));
        $this->assertSame('2026-03-08 06:30:00', $this->storedStart($springBefore));
        $this->assertSame('2026-03-08 07:30:00', $this->storedStart($springAfter));
        $this->assertSame('2026-06-16 15:00:00', $this->storedStart($utcVisit));
        $this->assertSame('2026-07-04 20:00:00', $this->storedStart($fallbackVisit));
        $this->assertSame('2026-11-01 05:30:00', $this->storedStart($creekVisit));
        $this->assertSame('2026-02-01 15:00:00', $this->storedStart($past));
        $this->assertSame('2026-11-01 16:00:00', $this->storedStart($held));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    /**
     * @return array{location: Location, asset: Asset}
     */
    private function place(Tenant $tenant, string $timezone, string $name): array
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => $timezone,
            'name' => $name,
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name.' boats',
            'slug' => strtolower($name).'-boats',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name.' boat',
            'status' => ResourceStatus::Retired,
            'replacement_asset_id' => Asset::factory()->create([
                'tenant_id' => $tenant->id,
                'location_id' => $location->id,
                'resource_category_id' => $category->id,
                'name' => $name.' spare',
            ])->id,
        ]);

        return ['location' => $location, 'asset' => $asset];
    }

    /**
     * @param  array{location: Location, asset: Asset}  $place
     */
    private function offer(Tenant $tenant, array $place, string $guest, string $startsUtc, BookingStatus $status = BookingStatus::Confirmed): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $guest,
            'email' => strtolower(str_replace(' ', '.', $guest)).'@example.com',
        ]);
        $starts = CarbonImmutable::parse($startsUtc, 'UTC');
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $place['location']->id,
            'name' => $guest.' rental',
            'kind' => ServiceKind::Rental,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $place['location']->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
            'participants' => 1,
            'payload_hash' => hash('sha256', $guest.$startsUtc),
        ]);
        Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $place['asset']->id,
            'quantity' => 1,
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
        ]);
        app(TenantManager::class)->forget();

        return $booking;
    }

    private function storedStart(Booking $booking): string
    {
        return Booking::withoutGlobalScope(TenantScope::class)
            ->findOrFail($booking->id)
            ->starts_at
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
}
