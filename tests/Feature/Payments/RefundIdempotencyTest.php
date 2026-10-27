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
use App\Models\PriceRule;
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

class RefundIdempotencyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_same_refund_key_returns_the_first_refund(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id);
        $other = $this->paidBooking($workspace['tenant']->id, 'pi_other');
        $spare = $this->paidBooking($workspace['tenant']->id, 'pi_spare');
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds');
        $key = str_repeat('r', 80);

        $this->postJson($url, [
            'reason' => 'operator_cancelled',
        ], ['Idempotency-Key' => str_repeat('k', 81)])->assertStatus(422)
            ->assertJsonPath('errors.idempotency_key.0', 'An idempotency key must be 80 characters or fewer.');

        $this->postJson($url, [
            'idempotency_key' => 'needs-reason',
        ])->assertStatus(422);

        $this->postJson($url, [
            'reason' => 'not-a-reason',
            'idempotency_key' => 'bad-reason',
        ])->assertStatus(422);

        $this->postJson($url, [
            'reason' => 'dispute_lost',
            'idempotency_key' => 'from-stripe',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'A dispute outcome comes from Stripe. It cannot be entered as a refund.');

        $first = $this->postJson($url, [
            'reason' => 'operator_cancelled',
        ], ['Idempotency-Key' => $key])->assertCreated()
            ->assertJsonPath('data.customer_refund_cents', 10_300)
            ->assertJsonPath('data.application_fee_refund_cents', 300)
            ->assertJsonPath('data.payment_status', 'refunded')
            ->json('data');
        $this->assertIsArray($first);
        $this->assertIsString($first['id']);

        $this->postJson($url, [
            'reason' => 'operator_cancelled',
            'refund_subtotal_cents' => 0,
            'refund_tax_cents' => 0,
            'idempotency_key' => $key,
        ])->assertCreated()->assertJsonPath('data.id', $first['id']);

        $this->postJson($url, [
            'reason' => 'customer_cancelled_partial_refund',
            'refund_subtotal_cents' => 5_000,
            'idempotency_key' => $key,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $this->postJson($url, [
            'reason' => 'operator_cancelled',
            'refund_tax_cents' => 1,
            'idempotency_key' => $key,
        ])->assertStatus(409);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$other->id.'/refunds'), [
            'reason' => 'operator_cancelled',
            'idempotency_key' => $key,
        ])->assertStatus(409);

        $spareUrl = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$spare->id.'/refunds');
        $partial = [
            'reason' => 'customer_cancelled_partial_refund',
            'refund_subtotal_cents' => 2_000,
        ];
        $retried = $this->postJson($spareUrl, [
            ...$partial,
            'idempotency_key' => 'needs-reason',
        ])->assertCreated()
            ->assertJsonPath('data.customer_refund_cents', 2_060)
            ->assertJsonPath('data.application_fee_refund_cents', 60)
            ->json('data');
        $this->assertIsArray($retried);
        $this->assertIsString($retried['id']);
        $this->assertNotSame($first['id'], $retried['id']);

        $afterBadReason = $this->postJson($spareUrl, [
            ...$partial,
            'idempotency_key' => 'bad-reason',
        ])->assertCreated()->json('data');
        $this->assertIsArray($afterBadReason);
        $this->assertIsString($afterBadReason['id']);
        $this->assertNotSame($retried['id'], $afterBadReason['id']);

        $afterDispute = $this->postJson($spareUrl, [
            ...$partial,
            'idempotency_key' => 'from-stripe',
        ])->assertCreated()->json('data');
        $this->assertIsArray($afterDispute);
        $this->assertIsString($afterDispute['id']);
        $this->assertNotSame($afterBadReason['id'], $afterDispute['id']);

        $this->assertCount(4, $fake->refunds);
        $this->assertSame(10_300, $fake->refunds[0]['params']['amount']);
        $this->assertTrue($fake->refunds[0]['params']['refund_application_fee']);

        $elsewhere = $this->workspace('other.test');
        $this->actingAsWorkspace($elsewhere);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
            'reason' => 'operator_cancelled',
            'idempotency_key' => $key,
        ])->assertNotFound();

        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $stored = Refund::query()->findOrFail($first['id']);
        $this->assertSame($key, $stored->idempotency_key);
        $this->assertSame($this->requestHash('operator_cancelled', 0, 0), $stored->payload_hash);
        $this->assertSame(10_000, $stored->refund_subtotal_cents);
        $this->assertSame(4, Refund::query()->count());
        $this->assertSame(0, Refund::query()->where('booking_id', $other->id)->count());
        $this->assertSame(3, Refund::query()->where('booking_id', $spare->id)->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.refunded')->where('booking_id', $booking->id)->count());
        $this->assertSame(PaymentStatus::Paid, $other->fresh()?->payment_status);
        $this->assertSame(PaymentStatus::Refunded, $booking->fresh()?->payment_status);
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(10_300, $snapshot->payload['customer_total_cents'] ?? null);
        $this->assertSame(300, $snapshot->payload['fee_cents'] ?? null);
        $this->assertSame(10_300, Payment::query()->where('booking_id', $booking->id)->firstOrFail()->amount_cents);
        $fee = PlatformFee::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(300, $fee->amount_cents);
        $this->assertSame(300, $fee->refunded_cents);
        $this->assertSame(0, PriceRule::query()->count());
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($elsewhere['tenant']);
        $this->assertSame(0, Refund::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_header_key_wins_over_the_body_key(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id);
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds');

        $first = $this->postJson($url, [
            'reason' => 'operator_cancelled',
            'idempotency_key' => 'body-key',
        ], ['Idempotency-Key' => 'header-key'])->assertCreated()->json('data');
        $this->assertIsArray($first);
        $this->assertIsString($first['id']);

        $this->postJson($url, [
            'reason' => 'operator_cancelled',
        ], ['Idempotency-Key' => 'header-key'])->assertCreated()
            ->assertJsonPath('data.id', $first['id']);

        $this->postJson($url, [
            'reason' => 'operator_cancelled',
            'idempotency_key' => 'body-key',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Nothing left to refund.');

        $this->assertCount(1, $fake->refunds);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('header-key', Refund::query()->findOrFail($first['id'])->idempotency_key);
        $this->assertSame(1, Refund::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_refund_without_a_key_is_issued_each_time(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id);
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds');
        $payload = [
            'reason' => 'customer_cancelled_partial_refund',
            'refund_subtotal_cents' => 2_000,
            'refund_tax_cents' => 0,
        ];

        $first = $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.customer_refund_cents', 2_060)
            ->assertJsonPath('data.application_fee_refund_cents', 60)
            ->assertJsonPath('data.payment_status', 'partially_refunded')
            ->json('data');
        $second = $this->postJson($url, $payload)->assertCreated()
            ->assertJsonPath('data.customer_refund_cents', 2_060)
            ->assertJsonPath('data.application_fee_refund_cents', 60)
            ->json('data');
        $this->assertIsArray($first);
        $this->assertIsArray($second);
        $this->assertIsString($first['id']);
        $this->assertIsString($second['id']);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertCount(2, $fake->refunds);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, Refund::query()->count());
        $this->assertSame(0, Refund::query()->whereNotNull('idempotency_key')->count());
        $this->assertSame(10_300, Payment::query()->where('booking_id', $booking->id)->firstOrFail()->amount_cents);
        $fee = PlatformFee::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(300, $fee->amount_cents);
        $this->assertSame(120, $fee->refunded_cents);
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->firstOrFail();
        $this->assertSame(10_300, $snapshot->payload['customer_total_cents'] ?? null);
        $this->assertSame(300, $snapshot->payload['fee_cents'] ?? null);
        $this->assertSame(0, PriceRule::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_refund_stored_before_payload_hashes_still_replays(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $booking = $this->paidBooking($workspace['tenant']->id);
        $url = $this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds');

        $first = $this->postJson($url, [
            'reason' => 'operator_cancelled',
            'idempotency_key' => 'legacy-key',
        ])->assertCreated()->json('data');
        $this->assertIsArray($first);

        app(TenantManager::class)->set($workspace['tenant']);
        $stored = Refund::query()->findOrFail($first['id']);
        $stored->payload_hash = null;
        $stored->save();
        app(TenantManager::class)->forget();

        $this->postJson($url, [
            'reason' => 'customer_cancelled_partial_refund',
            'refund_subtotal_cents' => 5_000,
            'idempotency_key' => 'legacy-key',
        ])->assertCreated()->assertJsonPath('data.id', $first['id']);

        $this->assertCount(1, $fake->refunds);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame(300, PlatformFee::query()->where('booking_id', $booking->id)->firstOrFail()->refunded_cents);
        app(TenantManager::class)->forget();
    }

    private function requestHash(string $reason, int $subtotal, int $tax): string
    {
        $encoded = json_encode([
            'reason' => $reason,
            'refund_subtotal_cents' => $subtotal,
            'refund_tax_cents' => $tax,
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $encoded);
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
