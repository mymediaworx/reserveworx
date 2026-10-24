<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\StripeGateway;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Location;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_full_refund_uses_the_stripe_flag_and_a_partial_does_not(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
            'reason' => 'operator_cancelled',
            'idempotency_key' => 'refund-full',
        ])->assertCreated()
            ->assertJsonPath('data.customer_refund_cents', 10_300)
            ->assertJsonPath('data.application_fee_refund_cents', 300)
            ->assertJsonPath('data.payment_status', 'refunded');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
            'reason' => 'operator_cancelled',
            'idempotency_key' => 'refund-full',
        ])->assertCreated();

        $this->assertCount(1, $fake->refunds);
        $this->assertSame(10_300, $fake->refunds[0]['params']['amount']);
        $this->assertTrue($fake->refunds[0]['params']['refund_application_fee']);
        $this->assertSame('acct_test_123', $fake->refunds[0]['account']);
        $this->assertSame([], $fake->feeRefunds);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(300, PlatformFee::query()->firstOrFail()->refunded_cents);
        $this->assertSame(1, Refund::query()->count());
        $hook = NotificationHook::query()->where('kind', 'booking.refunded')->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(10_300, $hook->payload['customer_refund_cents'] ?? null);
        $this->assertSame(300, $hook->payload['customer_fee_refund_cents'] ?? null);
        $this->assertSame(300, $hook->payload['application_fee_refund_cents'] ?? null);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.refunded')->where('booking_id', $booking->id)->count());
        app(TenantManager::class)->forget();

        $partial = $this->paidBooking($workspace['tenant']->id, 'pi_partial');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$partial->id.'/refunds'), [
            'reason' => 'customer_cancelled_partial_refund',
            'refund_subtotal_cents' => 5_000,
            'idempotency_key' => 'refund-part',
        ])->assertCreated()
            ->assertJsonPath('data.customer_refund_cents', 5_150)
            ->assertJsonPath('data.application_fee_refund_cents', 150)
            ->assertJsonPath('data.payment_status', 'partially_refunded');

        $this->assertArrayNotHasKey('refund_application_fee', $fake->refunds[1]['params']);
        $this->assertSame(5_150, $fake->refunds[1]['params']['amount']);
        $this->assertSame(150, $fake->feeRefunds[0]['amount']);
        $this->assertSame('fee_pi_partial', $fake->feeRefunds[0]['fee']);

        app(TenantManager::class)->set($workspace['tenant']);
        $partialHook = NotificationHook::query()->where('kind', 'booking.refunded')->where('booking_id', $partial->id)->firstOrFail();
        $this->assertSame(5_150, $partialHook->payload['customer_refund_cents'] ?? null);
        $this->assertSame(150, $partialHook->payload['customer_fee_refund_cents'] ?? null);
        $this->assertSame(150, $partialHook->payload['application_fee_refund_cents'] ?? null);
        app(TenantManager::class)->forget();
    }

    public function test_refunds_wait_for_a_platform_secret_and_a_lost_dispute_returns_only_the_fee(): void
    {
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
            'reason' => 'operator_cancelled',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Stripe is not configured. Add the platform secret before taking online payments.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Refund::query()->count());
        app(TenantManager::class)->forget();

        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => 'evt_dispute',
            'type' => 'charge.dispute.closed',
            'account' => 'acct_test_123',
            'data' => ['object' => [
                'id' => 'dp_test',
                'status' => 'lost',
                'payment_intent' => 'pi_test',
            ]],
        ], ['Stripe-Signature' => 'test-signature'])->assertOk();

        $this->assertSame([], $fake->refunds);
        $this->assertSame(300, $fake->feeRefunds[0]['amount']);
        $this->assertSame('fee_pi_test', $fake->feeRefunds[0]['fee']);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id))
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'disputed');
    }

    private function paidBooking(string $tenantId, string $intent = 'pi_test'): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => 'Kayak',
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 2,
            'payload_hash' => hash('sha256', $intent),
        ]);
        PricingSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'payload' => [
                'fee_payer' => 'customer_paid',
                'basis_cents' => 10_000,
                'fee_cents' => 300,
                'application_fee_cents' => 300,
                'tax_cents' => 0,
                'customer_total_cents' => 10_300,
            ],
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 10_300,
            'fee_cents' => 300,
            'stripe_payment_intent_id' => $intent,
        ]);
        PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => 300,
            'refunded_cents' => 0,
            'stripe_fee_id' => 'fee_'.$intent,
            'channel' => 'direct',
        ]);
        $account = StripeAccount::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->first();

        if (! $account instanceof StripeAccount) {
            StripeAccount::query()->create([
                'tenant_id' => $tenantId,
                'stripe_account_id' => 'acct_test_123',
                'card_payments_status' => 'active',
                'livemode' => false,
            ]);
        }

        return $booking;
    }
}
