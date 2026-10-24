<?php

namespace Tests\Feature\Platform;

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
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ManualFeeRefundTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_platform_administrator_refunds_a_booking_fee_without_refunding_the_customer(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test', tenantAttributes: ['name' => 'Dock', 'slug' => 'dock']);
        $booking = $this->paidBooking($workspace['tenant']->id);
        $fee = PlatformFee::withoutGlobalScope(TenantScope::class)->where('booking_id', $booking->id)->firstOrFail();
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $created = $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 100,
            'reason' => '  Goodwill after a duplicate fee  ',
            'idempotency_key' => 'fee-once',
        ])->assertCreated()
            ->assertJsonPath('data.platform_fee_id', $fee->id)
            ->assertJsonPath('data.amount_cents', 100)
            ->assertJsonPath('data.reason', 'Goodwill after a duplicate fee')
            ->assertJsonPath('data.rule', 'platform_manual')
            ->assertJsonPath('data.stripe_fee_refund_id', 'fr_test_1');

        $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 100,
            'reason' => 'Goodwill after a duplicate fee',
            'idempotency_key' => 'fee-once',
        ])->assertCreated()
            ->assertJsonPath('data.id', $created->json('data.id'));

        $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 50,
            'reason' => 'Goodwill after a duplicate fee',
            'idempotency_key' => 'fee-once',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $this->assertCount(1, $fake->feeRefunds);
        $this->assertSame('fee_pi_test', $fake->feeRefunds[0]['fee']);
        $this->assertSame(100, $fake->feeRefunds[0]['amount']);
        $this->assertSame([], $fake->refunds);

        $this->assertSame(1, PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, NotificationHook::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(100, PlatformFee::withoutGlobalScope(TenantScope::class)->find($fee->id)?->refunded_cents);
        $fresh = Booking::withoutGlobalScope(TenantScope::class)->find($booking->id);
        $this->assertInstanceOf(Booking::class, $fresh);
        $this->assertSame(PaymentStatus::Paid, $fresh->payment_status);

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_refunded')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertSame($workspace['tenant']->id, $log->tenant_id);
        $this->assertSame(100, $log->properties['amount_cents'] ?? null);
        $this->assertSame('Goodwill after a duplicate fee', $log->properties['reason'] ?? null);
        $this->assertSame('platform_manual', $log->properties['rule'] ?? null);

        $this->getJson('http://localhost/api/v1/platform/fee-revenue')
            ->assertOk()
            ->assertJsonPath('data.gross_cents', 300)
            ->assertJsonPath('data.refunded_cents', 100)
            ->assertJsonPath('data.manual_refunded_cents', 100)
            ->assertJsonPath('data.net_cents', 200)
            ->assertJsonPath('data.tenants.0.manual_refunded_cents', 100)
            ->assertJsonPath('data.tenants.0.channels.0.manual_refunded_cents', 100);

        $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 250,
            'reason' => 'Too much',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'The fee refund cannot exceed the booking fee still held.');

        $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 100,
            'reason' => 'operator_cancelled',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A manual fee refund uses its own reason, not a Section 7.7 reason code.');

        $this->postJson('http://localhost/api/v1/platform/fees/01ARZ3NDEKTSV4RRFFQ69G5FAV/refunds', [
            'amount_cents' => 100,
            'reason' => 'Missing fee',
        ])->assertNotFound();
    }

    public function test_a_manual_fee_refund_reduces_what_a_later_customer_refund_returns(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $booking = $this->paidBooking($workspace['tenant']->id);
        $fee = PlatformFee::withoutGlobalScope(TenantScope::class)->where('booking_id', $booking->id)->firstOrFail();
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 300,
            'reason' => 'The fee should not have been charged.',
        ])->assertCreated();

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
            'reason' => 'operator_cancelled',
        ])->assertCreated()
            ->assertJsonPath('data.customer_refund_cents', 10_300)
            ->assertJsonPath('data.application_fee_refund_cents', 0);

        $this->assertCount(1, $fake->feeRefunds);
        $this->assertSame(300, $fake->feeRefunds[0]['amount']);
        $this->assertCount(1, $fake->refunds);
        $this->assertArrayNotHasKey('refund_application_fee', $fake->refunds[0]['params']);
        $this->assertSame(10_300, $fake->refunds[0]['params']['amount']);
    }

    public function test_manual_fee_refunds_wait_for_stripe_and_are_refused_to_a_workspace_member(): void
    {
        $workspace = $this->workspace('dock.test');
        $booking = $this->paidBooking($workspace['tenant']->id);
        $fee = PlatformFee::withoutGlobalScope(TenantScope::class)->where('booking_id', $booking->id)->firstOrFail();
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 100,
            'reason' => 'Make it right',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Stripe is not configured. Add the platform secret before taking online payments.');

        $this->assertSame(0, PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count());

        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $fee->stripe_fee_id = null;
        $fee->save();

        $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 100,
            'reason' => 'Make it right',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'The Stripe fee has not been recorded yet.');

        $this->assertSame(0, PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame([], $fake->feeRefunds);

        $this->actingAsWorkspace($workspace);
        $this->postJson('http://localhost/api/v1/platform/fees/'.$fee->id.'/refunds', [
            'amount_cents' => 100,
            'reason' => 'Make it right',
        ])->assertForbidden();
    }

    private function paidBooking(string $tenantId): Booking
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
            'payload_hash' => hash('sha256', 'pi_test'),
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
            'stripe_payment_intent_id' => 'pi_test',
        ]);
        PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => 300,
            'refunded_cents' => 0,
            'stripe_fee_id' => 'fee_pi_test',
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
