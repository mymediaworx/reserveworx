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
use App\Models\LocationClosure;
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

class StaffClosureLocationTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_closure_windows_name_the_location_zone_including_both_fall_back_hours(): void
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

        $fall = $this->closure($workspace['tenant'], $harbor, '2026-11-01', '2026-11-01', 'Fall window', '01:00:00', '02:00:00');
        $allDay = $this->closure($workspace['tenant'], $harbor, '2026-11-01', '2026-11-01', 'Fall day', null, null);
        $spring = $this->closure($workspace['tenant'], $harbor, '2026-03-08', '2026-03-08', 'Spring window', '01:30:00', '03:30:00');
        $utcWindow = $this->closure($workspace['tenant'], $utc, '2026-06-15', '2026-06-15', 'Utc window', '18:00:00', '19:00:00');
        $fallbackWindow = $this->closure($workspace['tenant'], $fallback, '2026-06-15', '2026-06-15', 'Fallback window', '13:00:00', '14:00:00');
        $this->closure($other['tenant'], $creek, '2026-11-01', '2026-11-01', 'Creek only', '01:00:00', '02:00:00');

        $first = $this->booking($workspace['tenant'], $harbor, 'First Fall', 'first@example.com', '2026-11-01 05:30:00', '2026-11-01 05:45:00');
        $second = $this->booking($workspace['tenant'], $harbor, 'Second Fall', 'second@example.com', '2026-11-01 06:30:00', '2026-11-01 06:45:00');
        $this->booking($workspace['tenant'], $harbor, 'Held Guest', 'held@example.com', '2026-11-01 05:30:00', '2026-11-01 05:45:00', BookingStatus::Held);
        $this->booking($workspace['tenant'], $harbor, 'Cancelled Guest', 'cancelled@example.com', '2026-11-01 05:30:00', '2026-11-01 05:45:00', BookingStatus::Cancelled);
        $this->booking($other['tenant'], $creek, 'Creek Only', 'creek@example.com', '2026-11-01 05:30:00', '2026-11-01 05:45:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $page = (string) $this->get($this->tenantUrl('dock.test', '/staff/closures'))
            ->assertOk()
            ->assertSee('Closures')
            ->assertSee('Times are in America/Chicago.')
            ->assertSee('Fall window')
            ->assertSee('Sun, Nov 1, 1:00 AM EDT – 2:00 AM EST')
            ->assertSee('Fall day')
            ->assertSee('All day')
            ->assertSee('Sun, Nov 1, 12:00 AM EDT – Mon, Nov 2, 12:00 AM EST')
            ->assertSee('Spring window')
            ->assertSee('Sun, Mar 8, 1:30 AM EST – 3:30 AM EDT')
            ->assertSee('Utc window')
            ->assertSee('Mon, Jun 15, 6:00 PM – 7:00 PM UTC')
            ->assertSee('Fallback window')
            ->assertSee('Mon, Jun 15, 1:00 PM – 2:00 PM CDT')
            ->assertSee('First Fall')
            ->assertSee('Second Fall')
            ->assertSee('Sun, Nov 1, 1:30 AM – 1:45 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM – 1:45 AM EST')
            ->assertSee('data-closure="'.$fall->id.'"', false)
            ->assertSee('data-closure="'.$allDay->id.'"', false)
            ->assertSee('data-closure="'.$spring->id.'"', false)
            ->assertSee('data-closure="'.$utcWindow->id.'"', false)
            ->assertSee('data-closure="'.$fallbackWindow->id.'"', false)
            ->assertSee('href="/staff/bookings/'.$first->id.'"', false)
            ->assertSee('href="/staff/bookings/'.$second->id.'"', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.closure-page .when { overflow-wrap: anywhere; max-width: 100%; }', false)
            ->assertDontSee('Held Guest')
            ->assertDontSee('Cancelled Guest')
            ->assertDontSee('Creek Only')
            ->assertDontSee('Creek only')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('1:00 PM EDT')
            ->getContent();
        $this->assertSeeInOrder($page, [
            'Sun, Nov 1, 1:30 AM – 1:45 AM EDT',
            'Sun, Nov 1, 1:30 AM – 1:45 AM EST',
        ]);

        $this->actingAs($other['user']);
        $this->get($this->tenantUrl('creek.test', '/staff/closures'))
            ->assertOk()
            ->assertSee('Creek only')
            ->assertDontSee('Fall window')
            ->assertDontSee('First Fall');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $stored = LocationClosure::withoutGlobalScope(TenantScope::class)->findOrFail($fall->id);
        $storedFirst = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($first->id);
        $this->assertSame('01:00:00', substr((string) $stored->starts_local, 0, 8));
        $this->assertSame('02:00:00', substr((string) $stored->ends_local, 0, 8));
        $this->assertSame('2026-11-01 05:30:00', $storedFirst->starts_at?->utc()->format('Y-m-d H:i:s'));
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

        return $service->setRelation('location', $location);
    }

    private function closure(
        Tenant $tenant,
        Service $service,
        string $startsOn,
        string $endsOn,
        string $reason,
        ?string $startsLocal,
        ?string $endsLocal,
    ): LocationClosure {
        app(TenantManager::class)->set($tenant);
        $closure = LocationClosure::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $service->location_id,
            'reason' => $reason,
            'all_day' => $startsLocal === null,
            'recurs_yearly' => false,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'starts_local' => $startsLocal,
            'ends_local' => $endsLocal,
        ]);
        app(TenantManager::class)->forget();

        return $closure;
    }

    private function booking(
        Tenant $tenant,
        Service $service,
        string $customerName,
        string $email,
        string $startsUtc,
        string $endsUtc,
        BookingStatus $status = BookingStatus::Confirmed,
    ): Booking {
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
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => CarbonImmutable::parse($startsUtc, 'UTC'),
            'ends_at' => CarbonImmutable::parse($endsUtc, 'UTC'),
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
     * @param  list<string>  $needles
     */
    private function assertSeeInOrder(string $html, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = strpos($html, $needle, $offset);
            $this->assertNotFalse($position, $needle);
            $offset = $position + strlen($needle);
        }
    }
}
