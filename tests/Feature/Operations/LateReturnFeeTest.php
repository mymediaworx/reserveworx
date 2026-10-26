<?php

namespace Tests\Feature\Operations;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Returns;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\RentalReturn;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\CheckIn\BalanceDue;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class LateReturnFeeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_on_time_return_has_no_late_fee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:30:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $this->useTenant($alpha['tenant']);
        TenantSetting::query()->update([
            'late_fee_per_minute_cents' => 50,
            'late_grace_minutes' => 10,
        ]);
        $open = $this->openCheckout($alpha['tenant'], $north, 'On Time Guest', 'Harbor kayak', '2026-06-16 17:00:00', '2026-06-16 19:00:00');
        $this->price($alpha['tenant'], $open['booking']);
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/returns'))
            ->assertOk()
            ->assertSee('On Time Guest')
            ->assertDontSee('data-late-fee=', false)
            ->assertDontSee('Inside the grace period');

        $this->useTenant($alpha['tenant']);
        Livewire::test(Returns::class)
            ->set('returnedAt.'.$open['checkout']->id, '2026-06-16T18:20')
            ->set('condition.'.$open['checkout']->id, 'good')
            ->call('recordReturn', $open['checkout']->id)
            ->assertHasNoErrors()
            ->assertSee('Returned on time.');

        $this->useTenant($alpha['tenant']);
        $open['checkout']->refresh();
        $this->assertNull($open['checkout']->late_fee_cents);
        $row = RentalReturn::query()->where('rental_checkout_id', $open['checkout']->id)->firstOrFail();
        $this->assertSame(0, $row->minutes_late);
        $this->assertNull($row->late_fee_cents);
        $this->assertNull($row->late_fee_decision);
        $this->assertSame(11_100, $this->total($open['booking']));
        $this->assertSame(0, app(BalanceDue::class)->forBooking($open['booking']->refresh())['cents']);
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(1, Payment::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_return_inside_the_grace_period_has_no_late_fee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:15:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $this->useTenant($alpha['tenant']);
        TenantSetting::query()->update([
            'late_fee_per_minute_cents' => 50,
            'late_grace_minutes' => 15,
        ]);
        $inside = $this->openCheckout($alpha['tenant'], $north, 'Grace Guest', 'Grace kayak', '2026-06-16 17:00:00', '2026-06-16 18:00:00');
        $edge = $this->openCheckout($alpha['tenant'], $north, 'Edge Guest', 'Edge kayak', '2026-06-16 17:00:00', '2026-06-16 18:00:00');
        $this->price($alpha['tenant'], $inside['booking']);
        $this->price($alpha['tenant'], $edge['booking']);
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/returns'))
            ->assertOk()
            ->assertSee('Inside the grace period. No late fee.')
            ->assertSee('data-grace="yes"', false)
            ->assertDontSee('data-late-fee=', false);

        $this->useTenant($alpha['tenant']);
        Livewire::test(Returns::class)
            ->set('returnedAt.'.$inside['checkout']->id, '2026-06-16T18:10')
            ->set('condition.'.$inside['checkout']->id, 'good')
            ->call('recordReturn', $inside['checkout']->id)
            ->assertHasNoErrors()
            ->assertSee('Returned within the grace period.');
        Livewire::test(Returns::class)
            ->set('returnedAt.'.$edge['checkout']->id, '2026-06-16T18:15')
            ->set('condition.'.$edge['checkout']->id, 'good')
            ->call('recordReturn', $edge['checkout']->id)
            ->assertHasNoErrors()
            ->assertSee('Returned within the grace period.');

        $this->useTenant($alpha['tenant']);
        $this->assertNull($inside['checkout']->refresh()->late_fee_cents);
        $this->assertNull($edge['checkout']->refresh()->late_fee_cents);
        $this->assertSame(0, RentalReturn::query()->where('rental_checkout_id', $inside['checkout']->id)->firstOrFail()->minutes_late);
        $this->assertSame(0, RentalReturn::query()->where('rental_checkout_id', $edge['checkout']->id)->firstOrFail()->minutes_late);
        $this->assertSame(11_100, $this->total($inside['booking']));
        $this->assertSame(11_100, $this->total($edge['booking']));
        $this->assertSame(0, app(BalanceDue::class)->forBooking($inside['booking']->refresh())['cents']);
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_late_return_applies_the_fee_to_the_balance(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:30:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $this->useTenant($alpha['tenant']);
        TenantSetting::query()->update([
            'late_fee_per_minute_cents' => 50,
            'late_grace_minutes' => 10,
        ]);
        $open = $this->openCheckout($alpha['tenant'], $north, 'Late Guest', 'Late kayak', '2026-06-16 17:00:00', '2026-06-16 18:00:00');
        $this->price($alpha['tenant'], $open['booking']);
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/returns'))
            ->assertOk()
            ->assertSee('Late fee $10.00 for 20 minutes')
            ->assertSee('data-late-fee="1000"', false)
            ->assertSee('data-late-minutes="20"', false)
            ->assertSee('Apply fee')
            ->assertSee('Waive fee');

        $this->useTenant($alpha['tenant']);
        Livewire::test(Returns::class)
            ->set('returnedAt.'.$open['checkout']->id, '2026-06-16T18:30')
            ->set('condition.'.$open['checkout']->id, 'good')
            ->call('recordReturn', $open['checkout']->id)
            ->assertHasErrors(['lateFeeAction.'.$open['checkout']->id]);
        $this->assertNull($open['checkout']->refresh()->returned_at);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$open['checkout']->id, '2026-06-16T18:30')
            ->set('condition.'.$open['checkout']->id, 'good')
            ->set('lateFeeAction.'.$open['checkout']->id, 'applied')
            ->call('recordReturn', $open['checkout']->id)
            ->assertHasNoErrors()
            ->assertSee('20 minutes late');

        $this->useTenant($alpha['tenant']);
        $open['checkout']->refresh();
        $this->assertSame(1_000, $open['checkout']->late_fee_cents);
        $row = RentalReturn::query()->where('rental_checkout_id', $open['checkout']->id)->firstOrFail();
        $this->assertSame(20, $row->minutes_late);
        $this->assertSame(1_000, $row->late_fee_cents);
        $this->assertSame('applied', $row->late_fee_decision?->value);
        $snapshot = PricingSnapshot::query()->where('booking_id', $open['booking']->id)->firstOrFail();
        $this->assertSame(12_100, $snapshot->payload['customer_total_cents']);
        $this->assertSame(1_000, $snapshot->payload['late_fees_cents']);
        $this->assertSame(10_000, $snapshot->payload['basis_cents']);
        $this->assertSame(300, $snapshot->payload['fee_cents']);
        $this->assertArrayNotHasKey('charged_basis_cents', $snapshot->payload);
        $this->assertSame(1_000, $snapshot->payload['late_fee_lines'][0]['amount_cents']);
        $this->assertSame(20, $snapshot->payload['late_fee_lines'][0]['minutes_late']);
        $this->assertSame(1_000, app(BalanceDue::class)->forBooking($open['booking']->refresh())['cents']);
        $this->assertSame(1, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $audit = $this->audit($alpha['tenant']);
        $this->assertSame('applied', $audit->properties['late_fee_decision']);
        $this->assertSame(1_000, $audit->properties['late_fee_cents']);
        $this->assertArrayNotHasKey('late_fee_reason', $audit->properties ?? []);
    }

    public function test_a_late_return_can_be_waived_with_a_reason(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:30:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $this->useTenant($alpha['tenant']);
        TenantSetting::query()->update([
            'late_fee_per_minute_cents' => 50,
            'late_grace_minutes' => 10,
        ]);
        $open = $this->openCheckout($alpha['tenant'], $north, 'Waived Guest', 'Waived kayak', '2026-06-16 17:00:00', '2026-06-16 18:00:00');
        $this->price($alpha['tenant'], $open['booking']);
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);
        Livewire::test(Returns::class)
            ->set('returnedAt.'.$open['checkout']->id, '2026-06-16T18:30')
            ->set('condition.'.$open['checkout']->id, 'good')
            ->set('lateFeeAction.'.$open['checkout']->id, 'waived')
            ->call('recordReturn', $open['checkout']->id)
            ->assertHasErrors(['lateFeeReason.'.$open['checkout']->id]);
        $this->assertNull($open['checkout']->refresh()->returned_at);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$open['checkout']->id, '2026-06-16T18:30')
            ->set('condition.'.$open['checkout']->id, 'good')
            ->set('lateFeeAction.'.$open['checkout']->id, 'waived')
            ->set('lateFeeReason.'.$open['checkout']->id, 'Customer was helping another boat')
            ->call('recordReturn', $open['checkout']->id)
            ->assertHasNoErrors()
            ->assertSee('20 minutes late');

        $this->useTenant($alpha['tenant']);
        $this->assertNull($open['checkout']->refresh()->late_fee_cents);
        $row = RentalReturn::query()->where('rental_checkout_id', $open['checkout']->id)->firstOrFail();
        $this->assertSame(20, $row->minutes_late);
        $this->assertSame(1_000, $row->late_fee_cents);
        $this->assertSame('waived', $row->late_fee_decision?->value);
        $this->assertSame('Customer was helping another boat', $row->late_fee_reason);
        $this->assertSame(11_100, $this->total($open['booking']));
        $this->assertSame(0, app(BalanceDue::class)->forBooking($open['booking']->refresh())['cents']);
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $audit = $this->audit($alpha['tenant']);
        $this->assertSame('waived', $audit->properties['late_fee_decision']);
        $this->assertStringNotContainsString('helping another boat', (string) json_encode($audit->properties));
    }

    public function test_overdue_rentals_that_are_still_out_show_on_the_dashboard(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $north = $this->location($alpha['tenant'], 'North dock');
        $betaDock = $this->location($beta['tenant'], 'Beta dock');
        $overdue = $this->openCheckout($alpha['tenant'], $north, 'Overdue Guest', 'Overdue skiff', '2026-06-16 16:00:00', '2026-06-16 17:00:00');
        $later = $this->openCheckout($alpha['tenant'], $north, 'Later Guest', 'Later skiff', '2026-06-16 18:00:00', '2026-06-16 19:00:00');
        $back = $this->openCheckout($alpha['tenant'], $north, 'Back Guest', 'Back skiff', '2026-06-16 14:00:00', '2026-06-16 16:00:00');
        $this->useTenant($alpha['tenant']);
        $back['checkout']->returned_at = '2026-06-16 16:05:00';
        $back['checkout']->save();
        $this->openCheckout($beta['tenant'], $betaDock, 'Beta Guest', 'Beta skiff', '2026-06-16 16:00:00', '2026-06-16 17:00:00');
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('Still out')
            ->assertSee('Overdue skiff')
            ->assertSee('Overdue Guest')
            ->assertSee('60 minutes late')
            ->assertSee('data-overdue="1"', false)
            ->assertSee('data-overdue-list="1"', false)
            ->assertSee('data-overdue-checkout="'.$overdue['checkout']->id.'" data-minutes="60"', false)
            ->assertSee('data-checked-out="2"', false)
            ->assertSee('data-checked-out-later="1"', false)
            ->assertSee('data-checked-out-row="'.$later['checkout']->id.'"', false)
            ->assertSee('Later skiff')
            ->assertSee('Later Guest')
            ->assertSee('Due Tue, Jun 16, 7:00 PM')
            ->assertDontSee('data-checked-out-row="'.$overdue['checkout']->id.'"', false)
            ->assertDontSee('data-overdue-checkout="'.$later['checkout']->id.'"', false)
            ->assertDontSee('data-checked-out-row="'.$back['checkout']->id.'"', false)
            ->assertDontSee('data-overdue-checkout="'.$back['checkout']->id.'"', false)
            ->assertDontSee('Back skiff')
            ->assertDontSee('Beta skiff');
    }

    private function location(Tenant $tenant, string $name): Location
    {
        $this->useTenant($tenant);

        return Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => 'UTC',
        ]);
    }

    /**
     * @return array{booking: Booking, checkout: RentalCheckout, asset: Asset}
     */
    private function openCheckout(
        Tenant $tenant,
        Location $location,
        string $customerName,
        string $assetName,
        string $checkedOutAt,
        string $dueAt,
    ): array {
        $this->useTenant($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Day rental',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '.', $customerName)).'@example.com',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $assetName,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::InProgress,
            'starts_at' => $checkedOutAt,
            'ends_at' => $dueAt,
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerName.$assetName.$dueAt),
        ]);
        $checkout = RentalCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'checked_out_at' => $checkedOutAt,
            'due_at' => $dueAt,
        ]);
        Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'starts_at' => $checkedOutAt,
            'ends_at' => $dueAt,
        ]);

        return compact('booking', 'checkout', 'asset');
    }

    private function price(Tenant $tenant, Booking $booking): void
    {
        $this->useTenant($tenant);
        PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'payload' => [
                'services_cents' => 10_000,
                'basis_cents' => 10_000,
                'tax_cents' => 800,
                'fee_cents' => 300,
                'customer_fee_line_cents' => 300,
                'customer_total_cents' => 11_100,
                'fee_payer' => 'customer_paid',
            ],
        ]);
        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 11_100,
            'fee_cents' => 300,
        ]);
    }

    private function total(Booking $booking): int
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail();
        $total = $snapshot->payload['customer_total_cents'] ?? 0;

        return is_numeric($total) ? (int) $total : 0;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    private function audit(Tenant $tenant): AuditLog
    {
        return AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('action', 'rental.returned')
            ->latest('created_at')
            ->firstOrFail();
    }
}
