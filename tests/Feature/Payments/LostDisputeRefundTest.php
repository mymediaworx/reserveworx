<?php

namespace Tests\Feature\Payments;

use App\Domain\Fees\Refunds\FeeRefundCalculator;
use App\Domain\Payments\StripeGateway;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Location;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\WebhookEvent;
use App\Services\Booking\BookingCancellation;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class LostDisputeRefundTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_lost_dispute_returns_the_remaining_fee_and_records_the_rule(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id, 'pi_lost');

        $this->postDispute('evt_lost', 'dp_lost', 'lost', ['id' => 'pi_lost'])->assertOk();
        $this->postDispute('evt_lost', 'dp_lost', 'lost', ['id' => 'pi_lost'])->assertOk();
        $this->postDispute('evt_lost_again', 'dp_lost', 'lost', 'pi_lost')->assertOk();

        $this->assertSame([], $fake->refunds);
        $this->assertCount(1, $fake->feeRefunds);
        $this->assertSame(300, $fake->feeRefunds[0]['amount']);
        $this->assertSame('fee_pi_lost', $fake->feeRefunds[0]['fee']);
        $this->assertSame('dispute:dp_lost-fee', $fake->feeRefunds[0]['idempotency_key']);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id))
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'disputed');

        app(TenantManager::class)->set($workspace['tenant']);
        $refund = Refund::query()->firstOrFail();
        $this->assertSame('dispute_lost', $refund->reason);
        $this->assertSame('spec-1.0/7.7', $refund->rules_version);
        $this->assertSame(0, $refund->customer_refund_cents);
        $this->assertSame(0, $refund->customer_fee_refund_cents);
        $this->assertSame(300, $refund->application_fee_refund_cents);
        $this->assertSame(0, $refund->refund_subtotal_cents);
        $this->assertNull($refund->stripe_refund_id);
        $this->assertSame('fr_test_1', $refund->stripe_fee_refund_id);
        $this->assertSame('dispute:dp_lost', $refund->idempotency_key);
        $this->assertNull($refund->actor_user_id);
        $this->assertSame(300, PlatformFee::query()->firstOrFail()->refunded_cents);
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.refunded')->count());
        $audit = AuditLog::query()->where('action', 'payment.refund')->firstOrFail();
        $this->assertNull($audit->actor_user_id);
        $this->assertSame('system', $audit->properties['actor'] ?? null);
        $this->assertSame('dispute_lost', $audit->properties['reason_code'] ?? null);
        $this->assertSame('dispute_lost', $audit->properties['rule'] ?? null);
        $this->assertSame(FeeRefundCalculator::RULES_VERSION, $audit->properties['rules_version'] ?? null);
        $this->assertSame(300, $audit->properties['application_fee_refund_cents'] ?? null);
        $this->assertSame(0, $audit->properties['customer_refund_cents'] ?? null);
        $this->assertSame('dp_lost', $audit->properties['dispute_id'] ?? null);
        $this->assertTrue($audit->properties['customer_handled_by_dispute'] ?? false);
        $this->assertNotNull(WebhookEvent::query()->where('stripe_event_id', 'evt_lost')->firstOrFail()->processed_at);
        app(TenantManager::class)->forget();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/fee-ledger?booking_id='.$booking->id))
            ->assertOk()
            ->assertJsonPath('data.refunded_cents', 300)
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.amount_cents', 300)
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.reason', 'dispute_lost')
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.rule', 'dispute_lost')
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.actor_user_id', null);
    }

    public function test_a_won_dispute_and_a_closed_inquiry_return_nothing(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $won = $this->paidBooking($workspace['tenant']->id, 'pi_won');
        $inquiry = $this->paidBooking($workspace['tenant']->id, 'pi_inquiry');

        $this->postDispute('evt_won', 'dp_won', 'won', 'pi_won')->assertOk();
        $this->postDispute('evt_won_again', 'dp_won', 'won', 'pi_won')->assertOk();
        $this->postDispute('evt_inquiry', 'dp_inquiry', 'warning_closed', 'pi_inquiry')->assertOk();

        $this->assertSame([], $fake->refunds);
        $this->assertSame([], $fake->feeRefunds);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.refunded')->count());
        $this->assertSame(PaymentStatus::Paid, $won->fresh()->payment_status);
        $this->assertSame(PaymentStatus::Paid, $inquiry->fresh()->payment_status);
        $this->assertFalse(app(BookingCancellation::class)->refundIssued($won));
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.dispute_closed')->where('subject_id', $won->id)->count());
        $wonAudit = AuditLog::query()->where('action', 'payment.dispute_closed')->where('subject_id', $won->id)->firstOrFail();
        $this->assertSame('dispute_won', $wonAudit->properties['reason_code'] ?? null);
        $this->assertSame('won', $wonAudit->properties['status'] ?? null);
        $this->assertSame(0, $wonAudit->properties['application_fee_refund_cents'] ?? null);
        $this->assertSame('system', $wonAudit->properties['actor'] ?? null);
        $inquiryAudit = AuditLog::query()->where('subject_id', $inquiry->id)->where('action', 'payment.dispute_closed')->firstOrFail();
        $this->assertSame('warning_closed', $inquiryAudit->properties['status'] ?? null);
        $this->assertSame(0, (int) PlatformFee::query()->sum('refunded_cents'));
        app(TenantManager::class)->forget();
    }

    public function test_a_lost_dispute_after_a_partial_refund_returns_only_the_remaining_fee(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id, 'pi_part');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
            'reason' => 'customer_cancelled_partial_refund',
            'refund_subtotal_cents' => 5_000,
            'idempotency_key' => 'partial-before-dispute',
        ])->assertCreated()
            ->assertJsonPath('data.application_fee_refund_cents', 150);

        $this->postDispute('evt_after_partial', 'dp_after_partial', 'lost', 'pi_part')->assertOk();

        $this->assertCount(1, $fake->refunds);
        $this->assertArrayNotHasKey('refund_application_fee', $fake->refunds[0]['params']);
        $this->assertCount(2, $fake->feeRefunds);
        $this->assertSame(150, $fake->feeRefunds[1]['amount']);
        $this->assertSame('fee_pi_part', $fake->feeRefunds[1]['fee']);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(300, (int) Refund::query()->sum('application_fee_refund_cents'));
        $this->assertSame(5_150, (int) Refund::query()->sum('customer_refund_cents'));
        $dispute = Refund::query()->where('reason', 'dispute_lost')->firstOrFail();
        $this->assertSame(0, $dispute->customer_refund_cents);
        $this->assertSame(150, $dispute->application_fee_refund_cents);
        $this->assertSame(PaymentStatus::Disputed, $booking->fresh()->payment_status);
        $this->assertSame(300, PlatformFee::query()->firstOrFail()->refunded_cents);
        app(TenantManager::class)->forget();
    }

    public function test_a_lost_dispute_returns_the_fee_when_the_account_cannot_take_cards(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $booking = $this->paidBooking($workspace['tenant']->id, 'pi_restricted', cardPaymentsStatus: 'restricted');

        $this->postDispute('evt_restricted', 'dp_restricted', 'lost', 'pi_restricted')->assertOk();

        $this->assertSame([], $fake->refunds);
        $this->assertSame(300, $fake->feeRefunds[0]['amount']);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(PaymentStatus::Disputed, $booking->fresh()->payment_status);
        $this->assertSame(300, PlatformFee::query()->firstOrFail()->refunded_cents);
        app(TenantManager::class)->forget();
    }

    public function test_a_lost_dispute_waits_until_the_stripe_fee_is_stored(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $booking = $this->paidBooking($workspace['tenant']->id, 'pi_later', stripeFeeId: null);

        $this->postDispute('evt_later', 'dp_later', 'lost', 'pi_later')
            ->assertStatus(503)
            ->assertJsonPath('message', 'The Stripe fee has not been recorded yet.');

        $pending = WebhookEvent::query()->where('stripe_event_id', 'evt_later')->firstOrFail();
        $this->assertNull($pending->processed_at);
        $this->assertSame([], $fake->feeRefunds);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(PaymentStatus::Paid, $booking->fresh()->payment_status);
        PlatformFee::query()->where('booking_id', $booking->id)->update(['stripe_fee_id' => 'fee_pi_later']);
        app(TenantManager::class)->forget();

        $this->postDispute('evt_later', 'dp_later', 'lost', 'pi_later')->assertOk();
        $this->postDispute('evt_later', 'dp_later', 'lost', 'pi_later')->assertOk();

        $this->assertCount(1, $fake->feeRefunds);
        $this->assertSame(300, $fake->feeRefunds[0]['amount']);
        $this->assertSame('fee_pi_later', $fake->feeRefunds[0]['fee']);
        $this->assertNotNull(WebhookEvent::query()->where('stripe_event_id', 'evt_later')->firstOrFail()->processed_at);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(PaymentStatus::Disputed, $booking->fresh()->payment_status);
        app(TenantManager::class)->forget();
    }

    public function test_an_unknown_disputed_payment_is_left_unprocessed(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $this->workspace('dock.test');

        $this->postDispute('evt_missing', 'dp_missing', 'lost', 'pi_missing')
            ->assertStatus(503)
            ->assertJsonPath('message', 'The disputed payment is not recorded yet.');

        $this->assertNull(WebhookEvent::query()->where('stripe_event_id', 'evt_missing')->firstOrFail()->processed_at);
        $this->assertSame([], $fake->feeRefunds);
        $this->assertSame([], $fake->refunds);
    }

    public function test_a_lost_dispute_on_the_balance_charge_still_returns_the_booking_fee(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $booking = $this->paidBooking($workspace['tenant']->id, 'pi_deposit', amountCents: 5_000);

        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'balance_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 5_300,
            'fee_cents' => 0,
            'stripe_payment_intent_id' => 'pi_balance',
        ]);

        $this->postDispute('evt_balance', 'dp_balance', 'lost', 'pi_balance')->assertOk();

        $this->assertSame([], $fake->refunds);
        $this->assertSame(300, $fake->feeRefunds[0]['amount']);
        $this->assertSame('fee_pi_deposit', $fake->feeRefunds[0]['fee']);

        app(TenantManager::class)->set($workspace['tenant']);
        $refund = Refund::query()->firstOrFail();
        $this->assertSame(0, $refund->customer_refund_cents);
        $this->assertSame(300, $refund->application_fee_refund_cents);
        $this->assertSame(PaymentStatus::Disputed, $booking->fresh()->payment_status);
        app(TenantManager::class)->forget();
    }

    public function test_a_lost_dispute_with_nothing_left_marks_the_booking_disputed(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id, 'pi_full');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
            'reason' => 'operator_cancelled',
            'idempotency_key' => 'full-before-dispute',
        ])->assertCreated();

        $this->postDispute('evt_after_full', 'dp_after_full', 'lost', 'pi_full')->assertOk();
        $this->postDispute('evt_after_full_again', 'dp_after_full', 'lost', 'pi_full')->assertOk();

        $this->assertCount(1, $fake->refunds);
        $this->assertTrue($fake->refunds[0]['params']['refund_application_fee']);
        $this->assertSame([], $fake->feeRefunds);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(PaymentStatus::Disputed, $booking->fresh()->payment_status);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.dispute_closed')->count());
        $closed = AuditLog::query()->where('action', 'payment.dispute_closed')->firstOrFail();
        $this->assertSame('dispute_lost', $closed->properties['reason_code'] ?? null);
        $this->assertSame(0, $closed->properties['application_fee_refund_cents'] ?? null);
        $this->assertSame('system', $closed->properties['actor'] ?? null);
        app(TenantManager::class)->forget();
    }

    public function test_an_operator_absorbed_lost_dispute_returns_the_fee_only(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $booking = $this->paidBooking($workspace['tenant']->id, 'pi_absorbed', feePayer: 'operator_absorbed');

        $this->postDispute('evt_absorbed', 'dp_absorbed', 'lost', 'pi_absorbed')->assertOk();

        $this->assertSame([], $fake->refunds);
        $this->assertSame(300, $fake->feeRefunds[0]['amount']);

        app(TenantManager::class)->set($workspace['tenant']);
        $refund = Refund::query()->firstOrFail();
        $this->assertSame(0, $refund->customer_refund_cents);
        $this->assertSame(0, $refund->customer_fee_refund_cents);
        $this->assertSame(300, $refund->application_fee_refund_cents);
        $this->assertSame(PaymentStatus::Disputed, $booking->fresh()->payment_status);
        app(TenantManager::class)->forget();
    }

    public function test_staff_cannot_enter_a_dispute_outcome_as_a_refund(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id, 'pi_staff');

        foreach (['dispute_lost', 'dispute_won'] as $reason) {
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
                'reason' => $reason,
                'idempotency_key' => 'staff-'.$reason,
            ])->assertStatus(422)
                ->assertJsonPath('message', 'A dispute outcome comes from Stripe. It cannot be entered as a refund.');
        }

        $this->assertSame([], $fake->refunds);
        $this->assertSame([], $fake->feeRefunds);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(PaymentStatus::Paid, $booking->fresh()->payment_status);
        app(TenantManager::class)->forget();
    }

    /**
     * @param  string|array{id: string}  $intent
     */
    private function postDispute(string $eventId, string $disputeId, string $status, string|array $intent): TestResponse
    {
        return $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => $eventId,
            'type' => 'charge.dispute.closed',
            'account' => 'acct_test_123',
            'data' => ['object' => [
                'id' => $disputeId,
                'status' => $status,
                'payment_intent' => $intent,
            ]],
        ], ['Stripe-Signature' => 'test-signature']);
    }

    private function paidBooking(
        string $tenantId,
        string $intent,
        string $feePayer = 'customer_paid',
        ?string $stripeFeeId = '',
        string $cardPaymentsStatus = 'active',
        int $amountCents = 10_300,
    ): Booking {
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
                'fee_payer' => $feePayer,
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
            'amount_cents' => $amountCents,
            'fee_cents' => 300,
            'stripe_payment_intent_id' => $intent,
        ]);
        PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => 300,
            'refunded_cents' => 0,
            'stripe_fee_id' => $stripeFeeId === '' ? 'fee_'.$intent : $stripeFeeId,
            'channel' => 'direct',
        ]);
        $account = StripeAccount::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->first();

        if (! $account instanceof StripeAccount) {
            StripeAccount::query()->create([
                'tenant_id' => $tenantId,
                'stripe_account_id' => 'acct_test_123',
                'card_payments_status' => $cardPaymentsStatus,
                'livemode' => false,
            ]);
        }

        return $booking;
    }
}
