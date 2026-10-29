<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\QuickSearch;
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
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffQuickSearchLocationTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_search_names_each_booking_start_in_the_location_zone(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->place($workspace['tenant'], 'America/New_York', 'Harbor');
        $utc = $this->place($workspace['tenant'], 'UTC', 'Utc');
        $fallback = $this->place($workspace['tenant'], 'Not/AZone', 'Fallback');
        $creek = $this->place($other['tenant'], 'America/New_York', 'Creek');

        $fallEarly = $this->booking($workspace['tenant'], $harbor, 'Kayak Early', '2026-11-01 05:30:00');
        $fallLate = $this->booking($workspace['tenant'], $harbor, 'Kayak Late', '2026-11-01 06:30:00');
        $springBefore = $this->booking($workspace['tenant'], $harbor, 'Kayak Before', '2026-03-08 06:30:00');
        $springAfter = $this->booking($workspace['tenant'], $harbor, 'Kayak After', '2026-03-08 07:30:00');
        $utcBooking = $this->booking($workspace['tenant'], $utc, 'Kayak Utc', '2026-06-16 15:00:00');
        $fallbackBooking = $this->booking($workspace['tenant'], $fallback, 'Kayak Fallback', '2026-07-04 20:00:00');
        $creekBooking = $this->booking($other['tenant'], $creek, 'Kayak Creek', '2026-11-01 05:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Kayak')
            ->assertSee('Kayak Early')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Kayak Late')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('Kayak Before')
            ->assertSee('Sun, Mar 8, 1:30 AM EST')
            ->assertSee('Kayak After')
            ->assertSee('Sun, Mar 8, 3:30 AM EDT')
            ->assertSee('Kayak Utc')
            ->assertSee('Tue, Jun 16, 3:00 PM UTC')
            ->assertSee('Kayak Fallback')
            ->assertSee('Sat, Jul 4, 3:00 PM CDT')
            ->assertSee('data-when', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.staff-search-meta { min-width: 0; max-width: 100%; overflow-wrap: anywhere; }', false)
            ->assertDontSee('1:30 AM ·')
            ->assertDontSee('Kayak Creek')
            ->assertDontSee('3:00 PM CST')
            ->assertDontSee('4:00 PM EDT')
            ->assertDontSee('Tue, Jun 16, 11:00 AM EDT')
            ->assertDontSee('Tue, Jun 16, 10:00 AM CDT')
            ->assertDontSee('Sat, Jul 4, 4:00 PM EDT')
            ->assertDontSee('Sat, Jul 4, 8:00 PM UTC')
            ->assertDontSee('2:30 AM')
            ->assertDontSee('America/Chicago')
            ->assertDontSee('America/New_York')
            ->assertDontSee('Not/AZone');

        app(TenantManager::class)->set($other['tenant']);
        $this->actingAs($other['user']);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Kayak')
            ->assertSee('Kayak Creek')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertDontSee('Kayak Early')
            ->assertDontSee('Kayak Fallback')
            ->assertDontSee('Kayak Utc');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $stored = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($fallEarly->id);
        $this->assertSame('2026-11-01 05:30:00', $stored->starts_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(
            '2026-11-01 06:30:00',
            Booking::withoutGlobalScope(TenantScope::class)->findOrFail($fallLate->id)->starts_at?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-03-08 06:30:00',
            Booking::withoutGlobalScope(TenantScope::class)->findOrFail($springBefore->id)->starts_at?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-03-08 07:30:00',
            Booking::withoutGlobalScope(TenantScope::class)->findOrFail($springAfter->id)->starts_at?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-06-16 15:00:00',
            Booking::withoutGlobalScope(TenantScope::class)->findOrFail($utcBooking->id)->starts_at?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-07-04 20:00:00',
            Booking::withoutGlobalScope(TenantScope::class)->findOrFail($fallbackBooking->id)->starts_at?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-11-01 05:30:00',
            Booking::withoutGlobalScope(TenantScope::class)->findOrFail($creekBooking->id)->starts_at?->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
        $this->assertNotSame($fallEarly->id, $fallLate->id);
    }

    /**
     * @return array{location: Location, service: Service}
     */
    private function place(Tenant $tenant, string $timezone, string $name): array
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
            'name' => $name.' rental',
            'kind' => ServiceKind::Rental,
        ]);
        app(TenantManager::class)->forget();

        return compact('location', 'service');
    }

    /**
     * @param  array{location: Location, service: Service}  $place
     */
    private function booking(Tenant $tenant, array $place, string $guest, string $startsUtc): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $guest,
            'email' => strtolower(str_replace(' ', '.', $guest)).'@example.com',
        ]);
        $starts = CarbonImmutable::parse($startsUtc, 'UTC');
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $place['location']->id,
            'service_id' => $place['service']->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
            'participants' => 1,
            'payload_hash' => hash('sha256', $guest.$startsUtc),
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
}
