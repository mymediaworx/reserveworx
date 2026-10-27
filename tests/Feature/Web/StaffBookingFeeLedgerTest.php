<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\DisclosureKind;
use App\Enums\FeePayer;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DisclosureVersion;
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
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffBookingFeeLedgerTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_see_the_stored_fee_ledger_on_the_booking_page(): void
    {
        $workspace = $this->workspace('alpha.test', 'staff');
        $shop = $this->rentalShop($workspace['tenant']);
        $booking = $this->booking($workspace['tenant'], $shop['location'], $shop['service'], 'Sam River', 'sam@example.com');
        $empty = $this->booking($workspace['tenant'], $shop['location'], $shop['service'], 'Pat None', 'pat@example.com');
        $other = $this->booking($workspace['tenant'], $shop['location'], $shop['service'], 'Other Guest', 'other@example.com');
        $payment = $this->payment($workspace['tenant'], $booking, 'pi_staff_ledger');
        $direct = $this->fee($workspace['tenant'], $booking, $payment, 300, 150, 'fee_hidden_direct_ledger', 'direct', '2026-10-27 15:00:00');
        $third = $this->fee($workspace['tenant'], $booking, $payment, 200, 0, 'fee_hidden_third_party_ledger', 'third_party', '2026-10-27 15:05:00');
        $this->sectionRefund($workspace['tenant'], $booking, $payment, 'operator_cancelled', 100, $workspace['user'], '2026-10-27 16:00:00');
        $this->sectionRefund($workspace['tenant'], $booking, $payment, 'customer_cancelled_no_refund', 0, $workspace['user'], '2026-10-27 16:10:00');
        $this->manual(
            $workspace['tenant'],
            $direct,
            50,
            'Late start courtesy for the guest who waited at the dock.',
            $workspace['user'],
            '2026-10-27 16:20:00',
        );
        $otherPayment = $this->payment($workspace['tenant'], $other, 'pi_other_ledger');
        $this->fee($workspace['tenant'], $other, $otherPayment, 400, 400, 'fee_hidden_other_ledger', 'direct', '2026-10-27 15:10:00');
        $this->sectionRefund($workspace['tenant'], $other, $otherPayment, 'platform_oversell', 400, null, '2026-10-27 16:30:00');
        $this->quote($workspace['tenant'], $booking, 999);

        $beta = $this->workspace('beta.test', 'staff');
        $betaShop = $this->rentalShop($beta['tenant']);
        $betaBooking = $this->booking($beta['tenant'], $betaShop['location'], $betaShop['service'], 'Beta Guest', 'beta@example.com');

        $this->actingAs($workspace['user']);
        $before = $this->storedRows();

        $page = $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$booking->id));
        $page->assertOk()
            ->assertSee('Booking fee ledger')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('data-fee-ledger="2"', false)
            ->assertSee('data-ledger-charged="500"', false)
            ->assertSee('data-ledger-refunded="150"', false)
            ->assertSee('data-fee-charged="300"', false)
            ->assertSee('data-fee-charged="200"', false)
            ->assertSee('Charged $5.00')
            ->assertSee('Refunded $1.50')
            ->assertSee('Charged $3.00')
            ->assertSee('Direct')
            ->assertSee('Charged $2.00')
            ->assertSee('Third-party')
            ->assertSee('Refunded $1.00')
            ->assertSee('Operator cancelled')
            ->assertSee('Refunded $0.50')
            ->assertSee('Late start courtesy for the guest who waited at the dock.')
            ->assertSee('$9.99')
            ->assertDontSee('data-ledger-charged="999"', false)
            ->assertDontSee('data-fee-charged="999"', false)
            ->assertDontSee('Customer cancelled, no refund')
            ->assertDontSee('Oversold')
            ->assertDontSee('fee_hidden_direct_ledger')
            ->assertDontSee('fee_hidden_third_party_ledger')
            ->assertDontSee('including weather');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$empty->id))
            ->assertOk()
            ->assertSee('data-fee-ledger="0"', false)
            ->assertSee('No booking fee has been charged on this booking.')
            ->assertDontSee('Late start courtesy for the guest who waited at the dock.')
            ->assertDontSee('Operator cancelled');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$other->id))
            ->assertOk()
            ->assertSee('Oversold')
            ->assertSee('Charged $4.00')
            ->assertDontSee('Late start courtesy for the guest who waited at the dock.')
            ->assertDontSee('fee_hidden_other_ledger');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$betaBooking->id))
            ->assertNotFound();

        $this->assertSame($before, $this->storedRows());

        $this->app['auth']->forgetGuards();
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$booking->id))
            ->assertRedirect('/staff/login');
        $this->assertSame($before, $this->storedRows());
    }

    /**
     * @return array{payments: int, refunds: int, fees: int, fee_refunds: int, price_rules: int, tax_rules: int}
     */
    private function storedRows(): array
    {
        return [
            'payments' => $this->rows(Payment::class),
            'refunds' => $this->rows(Refund::class),
            'fees' => $this->rows(PlatformFee::class),
            'fee_refunds' => $this->rows(PlatformFeeRefund::class),
            'price_rules' => $this->rows(PriceRule::class),
            'tax_rules' => $this->rows(LocationTaxRule::class),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function rows(string $model): int
    {
        return $model::query()->withoutGlobalScope(TenantScope::class)->count();
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
            'starts_at' => '2026-11-03 15:00:00',
            'ends_at' => '2026-11-03 16:00:00',
            'participants' => 2,
            'payload_hash' => hash('sha256', $email),
        ]);
    }

    private function payment(Tenant $tenant, Booking $booking, string $intent): Payment
    {
        app(TenantManager::class)->set($tenant);

        return Payment::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 10_300,
            'fee_cents' => 300,
            'stripe_payment_intent_id' => $intent,
        ]);
    }

    private function fee(
        Tenant $tenant,
        Booking $booking,
        Payment $payment,
        int $amount,
        int $refunded,
        string $stripeFeeId,
        string $channel,
        string $at,
    ): PlatformFee {
        app(TenantManager::class)->set($tenant);
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => $amount,
            'refunded_cents' => $refunded,
            'stripe_fee_id' => $stripeFeeId,
            'channel' => $channel,
        ]);
        $this->stamp($fee, $at);

        return $fee;
    }

    private function manual(
        Tenant $tenant,
        PlatformFee $fee,
        int $amount,
        string $reason,
        User $actor,
        string $at,
    ): void {
        app(TenantManager::class)->set($tenant);
        $refund = PlatformFeeRefund::query()->create([
            'tenant_id' => $tenant->id,
            'platform_fee_id' => $fee->id,
            'amount_cents' => $amount,
            'reason' => $reason,
            'rule' => PlatformFeeRefund::RULE,
            'actor_user_id' => $actor->id,
        ]);
        $this->stamp($refund, $at);
    }

    private function sectionRefund(
        Tenant $tenant,
        Booking $booking,
        Payment $payment,
        string $reason,
        int $feeCents,
        ?User $actor,
        string $at,
    ): void {
        app(TenantManager::class)->set($tenant);
        $refund = Refund::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'reason' => $reason,
            'rules_version' => 'spec-1.0/7.7',
            'customer_refund_cents' => $feeCents,
            'customer_fee_refund_cents' => $feeCents,
            'application_fee_refund_cents' => $feeCents,
            'refund_subtotal_cents' => $feeCents === 0 ? 0 : 1_000,
            'refund_tax_cents' => 0,
            'actor_user_id' => $actor?->id,
        ]);
        $this->stamp($refund, $at);
    }

    private function quote(Tenant $tenant, Booking $booking, int $customerFeeLineCents): void
    {
        app(TenantManager::class)->set($tenant);
        $body = 'The stored terms stay on the quote.';
        $version = DisclosureVersion::query()->create([
            'tenant_id' => $tenant->id,
            'kind' => DisclosureKind::BookingFeeRefundTerms,
            'version' => 1,
            'fee_payer' => FeePayer::CustomerPaid,
            'rules_version' => 'spec-1.0/7.6-7.7/terms-1',
            'body' => $body,
            'content_hash' => hash('sha256', $body),
            'published_at' => '2026-10-27 14:00:00',
        ]);
        PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'disclosure_version_id' => $version->id,
            'payload' => [
                'fee_payer' => 'customer_paid',
                'basis_cents' => 10_000,
                'customer_fee_line_cents' => $customerFeeLineCents,
                'tax_cents' => 0,
                'customer_total_cents' => 10_000 + $customerFeeLineCents,
            ],
        ]);
    }

    private function stamp(Model $model, string $at): void
    {
        $model->forceFill([
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();
    }
}
