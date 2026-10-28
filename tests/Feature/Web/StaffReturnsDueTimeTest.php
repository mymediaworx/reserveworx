<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffReturnsDueTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_return_due_times_name_the_location_zone_across_daylight_saving(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 16:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->place($workspace['tenant'], 'America/New_York', 'Harbor');
        $pacific = $this->place($workspace['tenant'], 'America/Los_Angeles', 'Pacific');
        $utc = $this->place($workspace['tenant'], 'UTC', 'Utc');
        $fallback = $this->place($workspace['tenant'], 'Not/AZone', 'Fallback');
        $creek = $this->place($other['tenant'], 'America/New_York', 'Creek');

        $fallEarly = $this->checkout($workspace['tenant'], $harbor, 'Fall Early', '2026-11-01 05:30:00');
        $fallLate = $this->checkout($workspace['tenant'], $harbor, 'Fall Late', '2026-11-01 06:30:00');
        $springBefore = $this->checkout($workspace['tenant'], $harbor, 'Spring Before', '2026-03-08 06:30:00');
        $springAfter = $this->checkout($workspace['tenant'], $harbor, 'Spring After', '2026-03-08 07:30:00');
        $later = $this->checkout($workspace['tenant'], $harbor, 'Later Today', '2026-11-01 21:00:00');
        $pacificToday = $this->checkout($workspace['tenant'], $pacific, 'Pacific Today', '2026-11-02 06:00:00');
        $utcDue = $this->checkout($workspace['tenant'], $utc, 'Utc Guest', '2026-06-16 15:00:00');
        $fallbackDue = $this->checkout($workspace['tenant'], $fallback, 'Fallback Guest', '2026-07-04 20:00:00');
        $creekDue = $this->checkout($other['tenant'], $creek, 'Creek Guest', '2026-11-01 05:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $page = $this->get($this->tenantUrl('dock.test', '/staff/returns'));
        $page->assertOk()
            ->assertSee('Fall Early')
            ->assertSee('Due Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Fall Late')
            ->assertSee('Due Sun, Nov 1, 1:30 AM EST')
            ->assertSee('Spring Before')
            ->assertSee('Due Sun, Mar 8, 1:30 AM EST')
            ->assertSee('Spring After')
            ->assertSee('Due Sun, Mar 8, 3:30 AM EDT')
            ->assertSee('Later Today')
            ->assertSee('Due Sun, Nov 1, 4:00 PM EST')
            ->assertSee('Pacific Today')
            ->assertSee('Due Sun, Nov 1, 10:00 PM PST')
            ->assertSee('Utc Guest')
            ->assertSee('Due Tue, Jun 16, 3:00 PM UTC')
            ->assertSee('Fallback Guest')
            ->assertSee('Due Sat, Jul 4, 3:00 PM CDT')
            ->assertSee('data-due', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Due Sun, Nov 1, 1:30 AM</span>')
            ->assertDontSee('Mon, Nov 2, 1:00 AM EST')
            ->assertDontSee('3:00 PM CST')
            ->assertDontSee('4:00 PM CDT')
            ->assertDontSee('Tue, Jun 16, 11:00 AM EDT')
            ->assertDontSee('Tue, Jun 16, 10:00 AM CDT')
            ->assertDontSee('Sat, Jul 4, 4:00 PM EDT')
            ->assertDontSee('Sat, Jul 4, 8:00 PM UTC')
            ->assertDontSee('2:30 AM')
            ->assertDontSee('Creek Guest')
            ->assertDontSee('America/Chicago')
            ->assertDontSee('America/New_York');

        $this->actingAs($other['user'])
            ->get($this->tenantUrl('creek.test', '/staff/returns'))
            ->assertOk()
            ->assertSee('Creek Guest')
            ->assertSee('Due Sun, Nov 1, 1:30 AM EDT')
            ->assertDontSee('Fall Early')
            ->assertDontSee('Pacific Today')
            ->assertDontSee('Fallback Guest');

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('creek.test', '/staff/returns'))
            ->assertForbidden()
            ->assertDontSee('Creek Guest')
            ->assertDontSee('Fall Early');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $stored = RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($fallEarly->id);
        $this->assertSame('2026-11-01 05:30:00', $stored->due_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($stored->returned_at);
        $this->assertNull($stored->late_fee_cents);
        $this->assertSame(
            '2026-11-02 06:00:00',
            RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($pacificToday->id)->due_at->utc()->format('Y-m-d H:i:s'),
        );
        $this->assertNotSame($fallEarly->id, $fallLate->id);
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
        $this->assertNotSame($creekDue->tenant_id, $utcDue->tenant_id);
        $this->assertNotSame($springBefore->id, $springAfter->id);
        $this->assertNotSame($later->id, $fallbackDue->id);
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
            'name' => $name.' Kayak',
            'kind' => ServiceKind::Rental,
        ]);
        app(TenantManager::class)->forget();

        return compact('location', 'service');
    }

    /**
     * @param  array{location: Location, service: Service}  $place
     */
    private function checkout(Tenant $tenant, array $place, string $guest, string $dueUtc): RentalCheckout
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $guest,
            'email' => strtolower(str_replace(' ', '.', $guest)).'@example.com',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $place['location']->id,
            'name' => $guest.' boat',
        ]);
        $due = CarbonImmutable::parse($dueUtc, 'UTC');
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $place['location']->id,
            'service_id' => $place['service']->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::InProgress,
            'starts_at' => $due->subHour(),
            'ends_at' => $due,
            'participants' => 1,
            'payload_hash' => hash('sha256', $guest.$dueUtc),
        ]);
        $checkout = RentalCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'checked_out_at' => $due->subHour(),
            'due_at' => $due,
        ]);
        app(TenantManager::class)->forget();

        return $checkout;
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
