<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Returns;
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
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffReturnClockTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_return_keeps_both_fall_back_hours_and_refuses_a_missing_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 05:30:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update([
                'timezone' => 'America/Chicago',
                'late_fee_per_minute_cents' => 80,
            ]);

        $harbor = $this->place($workspace['tenant'], 'America/New_York', 'Harbor');
        $utc = $this->place($workspace['tenant'], 'UTC', 'Utc');
        $fallback = $this->place($workspace['tenant'], 'Not/AZone', 'Fallback');
        $creek = $this->place($other['tenant'], 'America/New_York', 'Creek');

        $early = $this->checkout($workspace['tenant'], $harbor, 'Fall Early', '2026-11-01 05:30:00');
        $later = $this->checkout($workspace['tenant'], $harbor, 'Fall Late', '2026-11-01 06:30:00');
        $preview = $this->checkout($workspace['tenant'], $harbor, 'Fall Preview', '2026-11-01 05:30:00');
        $spring = $this->checkout($workspace['tenant'], $harbor, 'Spring Guest', '2026-03-08 07:30:00');
        $utcDue = $this->checkout($workspace['tenant'], $utc, 'Utc Guest', '2026-07-20 15:00:00');
        $fallbackDue = $this->checkout($workspace['tenant'], $fallback, 'Fallback Guest', '2026-11-01 07:30:00');
        $creekDue = $this->checkout($other['tenant'], $creek, 'Creek Guest', '2026-11-01 05:30:00');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $page = $this->get($this->tenantUrl('dock.test', '/staff/returns'));
        $page->assertOk()
            ->assertSee('Return times use that rental’s location time zone.')
            ->assertSee('data-return-label="2026-11-01T01:30-04:00"', false)
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Due Sun, Nov 1, 1:30 AM EST')
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('white-space: normal', false)
            ->assertDontSee('Not/AZone')
            ->assertDontSee('Creek Guest')
            ->assertDontSee('America/Chicago');

        $this->useTenant($workspace['tenant']);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$early->id, '2026-11-01T01:30')
            ->assertSee('data-return-choice="2026-11-01T01:30-04:00"', false)
            ->assertSee('data-return-choice="2026-11-01T01:30-05:00"', false)
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->call('useReturnTime', $early->id, '2026-11-01T01:30-05:00')
            ->assertSet('returnedAt.'.$early->id, '2026-11-01T01:30-05:00')
            ->set('returnedAt.'.$early->id, '2026-11-01T01:30');

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$spring->id, '2026-03-08T02:30')
            ->call('recordReturn', $spring->id)
            ->assertHasErrors(['returnedAt.'.$spring->id => 'That time does not exist in this location.']);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$early->id, '2026-11-01T01:30+00:00')
            ->call('recordReturn', $early->id)
            ->assertHasErrors(['returnedAt.'.$early->id => 'That time does not exist in this location.']);

        $this->assertNull(RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($spring->id)->returned_at);
        $this->assertNull(RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($early->id)->returned_at);
        $this->assertSame($audits, $this->rows(AuditLog::class));

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$preview->id, '2026-11-01T01:30-05:00')
            ->assertSee('Late fee $48.00 for 60 minutes')
            ->assertSee('data-return-label="2026-11-01T01:30-05:00"', false)
            ->assertDontSee('data-return-choice=', false);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$early->id, '2026-11-01T01:30')
            ->call('recordReturn', $early->id)
            ->assertHasNoErrors();

        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00:00', 'UTC'));

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$later->id, '2026-11-01T01:30-05:00')
            ->call('recordReturn', $later->id)
            ->assertHasNoErrors();

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$utcDue->id, '2026-07-20T15:00')
            ->call('recordReturn', $utcDue->id)
            ->assertHasNoErrors();

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$fallbackDue->id, '2026-11-01T01:30-06:00')
            ->call('recordReturn', $fallbackDue->id)
            ->assertHasNoErrors();

        Livewire::test(Returns::class)
            ->call('recordReturn', $creekDue->id)
            ->assertNotFound();

        $this->assertSame('2026-11-01 05:30:00', $this->returned($early));
        $this->assertSame('2026-11-01 06:30:00', $this->returned($later));
        $this->assertSame('2026-07-20 15:00:00', $this->returned($utcDue));
        $this->assertSame('2026-11-01 07:30:00', $this->returned($fallbackDue));
        $this->assertNull(RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($preview->id)->returned_at);
        $this->assertNull(RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($preview->id)->late_fee_cents);
        $this->assertNull(RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($early->id)->late_fee_cents);
        $this->assertNull(RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($creekDue->id)->returned_at);
        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
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

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    private function returned(RentalCheckout $checkout): string
    {
        $stored = RentalCheckout::withoutGlobalScope(TenantScope::class)->findOrFail($checkout->id);

        return $stored->returned_at?->utc()->format('Y-m-d H:i:s') ?? '';
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }
}
