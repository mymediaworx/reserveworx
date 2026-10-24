<?php

namespace Tests\Feature\Payments;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Refund;
use App\Models\Service;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class FeeLedgerTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_ledger_lists_each_fee_and_the_reason_it_was_refunded(): void
    {
        $workspace = $this->workspace('dock.test');
        $tenantId = $workspace['tenant']->id;
        $actorId = $workspace['user']->id;
        $booking = $this->booking($tenantId);
        $later = $this->booking($tenantId);
        $unpriced = $this->booking($tenantId);
        $firstPayment = $this->payment($tenantId, $booking->id, 'pi_first');
        $increasePayment = $this->payment($tenantId, $booking->id, 'pi_increase');
        $firstFee = $this->fee($tenantId, $booking->id, $firstPayment->id, 300, 140, 'fee_first', '2026-06-01 10:00:00');
        $increaseFee = $this->fee($tenantId, $booking->id, $increasePayment->id, 63, 0, 'fee_increase', '2026-06-01 11:00:00');
        $webhookFee = $this->fee($tenantId, $later->id, null, 200, 150, 'fee_webhook', '2026-06-02 10:00:00');
        $manual = $this->manual($tenantId, $firstFee->id, 100, 'Goodwill after a weather day', $actorId, '2026-06-01 12:00:00');
        $partial = $this->sectionRefund($tenantId, $booking->id, $firstPayment->id, 'customer_cancelled_partial_refund', 40, $actorId, '2026-06-01 13:00:00');
        $this->sectionRefund($tenantId, $booking->id, $firstPayment->id, 'customer_cancelled_no_refund', 0, null, '2026-06-01 14:00:00');

        $this->actingAsWorkspace($workspace);
        $response = $this->getJson($this->tenantUrl('dock.test', '/api/v1/fee-ledger'))
            ->assertOk()
            ->assertJsonPath('data.charged_cents', 563)
            ->assertJsonPath('data.refunded_cents', 290)
            ->assertJsonPath('data.net_cents', 273)
            ->assertJsonPath('data.count', 3)
            ->assertJsonCount(2, 'data.bookings')
            ->assertJsonPath('data.bookings.0.booking_id', $booking->id)
            ->assertJsonPath('data.bookings.0.charged_cents', 363)
            ->assertJsonPath('data.bookings.0.refunded_cents', 140)
            ->assertJsonPath('data.bookings.0.fees.0.id', $firstFee->id)
            ->assertJsonPath('data.bookings.0.fees.0.payment_id', $firstPayment->id)
            ->assertJsonPath('data.bookings.0.fees.0.channel', 'direct')
            ->assertJsonPath('data.bookings.0.fees.0.charged_cents', 300)
            ->assertJsonPath('data.bookings.0.fees.0.refunded_cents', 140)
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.id', $manual->id)
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.amount_cents', 100)
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.reason', 'Goodwill after a weather day')
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.rule', 'platform_manual')
            ->assertJsonPath('data.bookings.0.fees.0.refunds.0.actor_user_id', $actorId)
            ->assertJsonPath('data.bookings.0.fees.0.refunds.1.id', $partial->id)
            ->assertJsonPath('data.bookings.0.fees.0.refunds.1.amount_cents', 40)
            ->assertJsonPath('data.bookings.0.fees.0.refunds.1.reason', 'customer_cancelled_partial_refund')
            ->assertJsonPath('data.bookings.0.fees.0.refunds.1.rule', 'customer_partial_refund')
            ->assertJsonPath('data.bookings.0.fees.0.refunds.1.actor_user_id', $actorId)
            ->assertJsonPath('data.bookings.0.fees.1.id', $increaseFee->id)
            ->assertJsonPath('data.bookings.0.fees.1.charged_cents', 63)
            ->assertJsonPath('data.bookings.0.fees.1.refunds', [])
            ->assertJsonPath('data.bookings.1.booking_id', $later->id)
            ->assertJsonPath('data.bookings.1.fees.0.id', $webhookFee->id)
            ->assertJsonPath('data.bookings.1.fees.0.refunded_cents', 150)
            ->assertJsonPath('data.bookings.1.fees.0.refunds', []);

        $ids = collect($response->json('data.bookings'))->pluck('booking_id')->all();
        $this->assertNotContains($unpriced->id, $ids);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/fee-ledger?booking_id='.$booking->id))
            ->assertOk()
            ->assertJsonPath('data.charged_cents', 363)
            ->assertJsonPath('data.refunded_cents', 140)
            ->assertJsonPath('data.net_cents', 223)
            ->assertJsonPath('data.count', 2)
            ->assertJsonCount(1, 'data.bookings')
            ->assertJsonPath('data.bookings.0.booking_id', $booking->id)
            ->assertJsonCount(2, 'data.bookings.0.fees.0.refunds');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/fee-ledger?booking_id='.$unpriced->id))
            ->assertOk()
            ->assertJsonPath('data.charged_cents', 0)
            ->assertJsonPath('data.refunded_cents', 0)
            ->assertJsonPath('data.count', 0)
            ->assertJsonPath('data.bookings.0.booking_id', $unpriced->id)
            ->assertJsonPath('data.bookings.0.fees', []);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/fee-ledger?booking_id=01ARZ3NDEKTSV4RRFFQ69G5FAV'))
            ->assertNotFound();
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/fee-ledger?booking_id=not-a-booking'))
            ->assertStatus(422);
    }

    public function test_another_workspaces_fees_stay_out_of_the_ledger(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $own = $this->booking($alpha['tenant']->id);
        $foreign = $this->booking($beta['tenant']->id);
        $this->fee($alpha['tenant']->id, $own->id, null, 300, 0, 'fee_alpha', '2026-06-01 10:00:00');
        $this->fee($beta['tenant']->id, $foreign->id, null, 900, 100, 'fee_beta', '2026-06-01 10:00:00');

        $this->actingAsWorkspace($alpha);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/fee-ledger'))
            ->assertOk()
            ->assertJsonPath('data.charged_cents', 300)
            ->assertJsonPath('data.refunded_cents', 0)
            ->assertJsonPath('data.count', 1)
            ->assertJsonPath('data.bookings.0.booking_id', $own->id);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/fee-ledger?booking_id='.$foreign->id))
            ->assertNotFound();
    }

    private function booking(string $tenantId): Booking
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

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 2,
            'payload_hash' => hash('sha256', $tenantId.$service->id),
        ]);
    }

    private function payment(string $tenantId, string $bookingId, string $intent): Payment
    {
        return Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 10_300,
            'fee_cents' => 300,
            'stripe_payment_intent_id' => $intent,
        ]);
    }

    private function fee(
        string $tenantId,
        string $bookingId,
        ?string $paymentId,
        int $amount,
        int $refunded,
        string $stripeFeeId,
        string $at,
    ): PlatformFee {
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'payment_id' => $paymentId,
            'amount_cents' => $amount,
            'refunded_cents' => $refunded,
            'stripe_fee_id' => $stripeFeeId,
            'channel' => 'direct',
        ]);
        $this->stamp($fee, $at);

        return $fee;
    }

    private function manual(
        string $tenantId,
        string $feeId,
        int $amount,
        string $reason,
        string $actorId,
        string $at,
    ): PlatformFeeRefund {
        $refund = PlatformFeeRefund::query()->create([
            'tenant_id' => $tenantId,
            'platform_fee_id' => $feeId,
            'amount_cents' => $amount,
            'reason' => $reason,
            'rule' => PlatformFeeRefund::RULE,
            'actor_user_id' => $actorId,
        ]);
        $this->stamp($refund, $at);

        return $refund;
    }

    private function sectionRefund(
        string $tenantId,
        string $bookingId,
        string $paymentId,
        string $reason,
        int $feeCents,
        ?string $actorId,
        string $at,
    ): Refund {
        $refund = Refund::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'payment_id' => $paymentId,
            'reason' => $reason,
            'rules_version' => 'spec-1.0/7.7',
            'customer_refund_cents' => $feeCents,
            'customer_fee_refund_cents' => $feeCents,
            'application_fee_refund_cents' => $feeCents,
            'refund_subtotal_cents' => $feeCents === 0 ? 0 : 1_000,
            'refund_tax_cents' => 0,
            'actor_user_id' => $actorId,
        ]);
        $this->stamp($refund, $at);

        return $refund;
    }

    private function stamp(Model $model, string $at): void
    {
        $model->forceFill([
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();
    }
}
