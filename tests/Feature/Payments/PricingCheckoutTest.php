<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\StripeGateway;
use App\Enums\PaymentStatus;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PricingCheckoutTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_offline_payment_confirms_a_priced_booking_with_no_fee(): void
    {
        Http::fake();
        Mail::fake();
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekend',
            'kind' => 'base',
            'amount_cents' => 20_000,
            'window' => 'weekend',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/promo-codes'), [
            'code' => 'save10',
            'percent_off' => 10,
        ])->assertCreated();

        $priced = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'), [
            'promo_code' => 'SAVE10',
            'tax_basis_points' => 0,
        ])->assertOk()->json('data');

        $this->assertSame(10_000, $priced['payload']['services_cents']);
        $this->assertSame(1_000, $priced['payload']['discount_cents']);
        $this->assertSame(270, $priced['payload']['fee_cents']);
        $this->assertSame(9_270, $priced['payload']['customer_total_cents']);
        $this->assertNotNull($priced['disclosure_version_id']);

        $again = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'), [
            'promo_code' => 'NOPE',
        ])->assertOk()->json('data');
        $this->assertSame($priced['id'], $again['id']);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/offline-payment'))
            ->assertOk()
            ->assertJsonPath('data.fee_cents', 0)
            ->assertJsonPath('data.payment_status', 'offline_paid')
            ->assertJsonPath('data.status', 'confirmed');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, PlatformFee::query()->count());
        $payment = Payment::query()->firstOrFail();
        $this->assertSame(0, $payment->fee_cents);
        $this->assertSame(9_000, $payment->amount_cents);
        $this->assertSame(PaymentStatus::OfflinePaid, Booking::query()->findOrFail($bookingId)->payment_status);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'payment.completed')->count());
        $completed = NotificationHook::query()->where('kind', 'payment.completed')->firstOrFail();
        $this->assertSame($payment->id, $completed->payload['payment_id'] ?? null);
        $this->assertSame('offline', $completed->payload['method'] ?? null);
        $this->assertSame(9_000, $completed->payload['amount_cents'] ?? null);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.confirmed')->count());
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_checkout_is_refused_without_an_active_account_or_a_platform_secret(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertStatus(409)->assertJsonPath('message', 'Online payments are not enabled for this workspace.');

        app(TenantManager::class)->set($workspace['tenant']);
        $account = StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'pending',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertStatus(409)->assertJsonPath('message', 'Online payments are not enabled for this workspace.');

        app(TenantManager::class)->set($workspace['tenant']);
        $account->card_payments_status = 'active';
        $account->save();
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertStatus(409)->assertJsonPath('message', 'Stripe is not configured. Add the platform secret before taking online payments.');
    }

    public function test_a_paid_webhook_confirms_the_booking_and_records_the_fee(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);

        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $started = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk()->json('data');

        $this->assertSame('https://checkout.stripe.test/pay/cs_test_123', $started['checkout_url']);
        $this->assertSame(300, $started['fee_cents']);
        $this->assertSame(10_300, $started['amount_cents']);
        $this->assertSame('acct_test_123', $fake->sessions[0]['account']);
        $this->assertSame(300, $fake->sessions[0]['params']['payment_intent_data']['application_fee_amount']);
        $this->assertArrayNotHasKey('payment_method_types', $fake->sessions[0]['params']);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'held');

        $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => 'evt_paid',
            'type' => 'checkout.session.completed',
        ], ['Stripe-Signature' => 'nope'])->assertStatus(400);

        $session = [
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 10_300,
            'payment_intent' => 'pi_test_123',
            'metadata' => [
                'booking_id' => $bookingId,
                'tenant_id' => $workspace['tenant']->id,
            ],
        ];
        $this->postWebhook('evt_paid', 'checkout.session.completed', $session, 'acct_test_123')->assertOk();
        $this->postWebhook('evt_paid', 'checkout.session.completed', $session, 'acct_test_123')->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'paid');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, PlatformFee::query()->count());
        $this->assertSame(300, PlatformFee::query()->firstOrFail()->amount_cents);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, WebhookEvent::query()->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'payment.completed')->count());
        $completed = NotificationHook::query()->where('kind', 'payment.completed')->firstOrFail();
        $this->assertSame(Payment::query()->firstOrFail()->id, $completed->payload['payment_id'] ?? null);
        $this->assertSame('stripe', $completed->payload['method'] ?? null);
        $this->assertSame(10_300, $completed->payload['amount_cents'] ?? null);
        $this->assertSame(0, NotificationHook::query()->where('kind', 'payment.failed')->count());
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Http::assertNothingSent();

        $this->postWebhook('evt_fee', 'application_fee.created', [
            'id' => 'fee_123',
            'amount' => 300,
            'account' => 'acct_test_123',
        ], 'acct_test_123')->assertOk();
        $this->postWebhook('evt_fee_refund', 'application_fee.refunded', [
            'id' => 'fee_123',
            'amount_refunded' => 150,
        ])->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/fee-ledger'))
            ->assertOk()
            ->assertJsonPath('data.charged_cents', 300)
            ->assertJsonPath('data.refunded_cents', 150)
            ->assertJsonPath('data.net_cents', 150)
            ->assertJsonPath('data.count', 1);

        $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => 'evt_capability',
            'type' => 'v2.core.account.updated',
            'data' => ['object' => [
                'id' => 'acct_test_123',
                'configuration' => [
                    'merchant' => [
                        'capabilities' => [
                            'card_payments' => ['status' => 'restricted'],
                        ],
                    ],
                ],
            ]],
        ], ['Stripe-Signature' => 'test-signature'])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('restricted', StripeAccount::query()->firstOrFail()->card_payments_status);
        $this->assertSame('fee_123', PlatformFee::query()->firstOrFail()->stripe_fee_id);
        app(TenantManager::class)->forget();
    }

    public function test_a_failed_async_checkout_queues_one_hook_and_leaves_the_hold(): void
    {
        Http::fake();
        Mail::fake();
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);

        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();

        $session = [
            'id' => 'cs_test_123',
            'payment_status' => 'unpaid',
            'metadata' => [
                'booking_id' => $bookingId,
                'tenant_id' => $workspace['tenant']->id,
            ],
        ];
        $this->postWebhook('evt_fail', 'checkout.session.async_payment_failed', $session, 'acct_test_123')->assertOk();
        $this->postWebhook('evt_fail', 'checkout.session.async_payment_failed', $session, 'acct_test_123')->assertOk();
        $this->postWebhook('evt_fail_again', 'checkout.session.async_payment_failed', $session, 'acct_test_123')->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'held');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('pending', Payment::query()->firstOrFail()->status);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'payment.failed')->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'payment.completed')->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.confirmed')->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.cancelled')->count());
        $failed = NotificationHook::query()->where('kind', 'payment.failed')->firstOrFail();
        $this->assertSame('payment.failed:cs_test_123', $failed->dedupe_key);
        $this->assertSame($bookingId, $failed->booking_id);
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();
        app(TenantManager::class)->set($workspace['tenant']);
        $skipped = NotificationHook::query()->where('kind', 'payment.failed')->firstOrFail();
        $this->assertSame('skipped', $skipped->delivery_status);
        $this->assertSame('No transactional email provider is configured.', $skipped->delivery_error);
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_a_repeated_payment_key_returns_the_original_row(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);

        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $first = $this->hold($service, '-a');
        $second = $this->hold($service, '-b', '2026-06-15T17:00:00Z', '2026-06-15T18:00:00Z');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/pricing'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/pricing'))->assertOk();

        $paid = $this->postJson(
            $this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/offline-payment'),
            [],
            ['Idempotency-Key' => 'offline-1'],
        )->assertOk();
        $again = $this->postJson(
            $this->tenantUrl('dock.test', '/api/v1/bookings/'.$first.'/offline-payment'),
            [],
            ['Idempotency-Key' => 'offline-1'],
        )->assertOk();

        $this->assertSame($paid->json('data.payment_id'), $again->json('data.payment_id'));
        $this->postJson(
            $this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/offline-payment'),
            ['idempotency_key' => 'offline-1'],
        )->assertStatus(409);

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'payment.completed')->count());
        app(TenantManager::class)->forget();

        $checkout = [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'card-1',
        ];
        $started = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/checkout-session'), $checkout)
            ->assertOk();
        $replay = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/checkout-session'), $checkout)
            ->assertOk();

        $this->assertSame($started->json('data.payment_id'), $replay->json('data.payment_id'));
        $this->assertSame('https://checkout.stripe.test/pay/cs_test_123', $started->json('data.checkout_url'));
        $this->assertNull($replay->json('data.checkout_url'));
        $this->assertCount(1, $fake->sessions);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.checkout_started')->count());
        $checkoutAudit = AuditLog::query()->where('action', 'payment.checkout_started')->firstOrFail();
        $this->assertSame($started->json('data.payment_id'), $checkoutAudit->subject_id);
        $this->assertSame(10_300, $checkoutAudit->properties['amount_cents'] ?? null);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$second.'/checkout-session'), [
            'success_url' => 'https://dock.test/other',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'card-1',
        ])->assertStatus(409)->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_an_unpaid_completion_waits_for_the_paid_event(): void
    {
        Http::fake();
        Mail::fake();
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);

        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service, '-async');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();

        $session = [
            'id' => 'cs_test_123',
            'amount_total' => 10_300,
            'payment_intent' => 'pi_test_123',
            'metadata' => [
                'booking_id' => $bookingId,
                'tenant_id' => $workspace['tenant']->id,
            ],
        ];
        $this->postWebhook('evt_completed_unpaid', 'checkout.session.completed', [
            ...$session,
            'payment_status' => 'unpaid',
        ], 'acct_test_123')->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'held');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('pending', Payment::query()->firstOrFail()->status);
        $this->assertSame(0, NotificationHook::query()->where('kind', 'payment.completed')->count());
        app(TenantManager::class)->forget();

        $this->postWebhook('evt_async_paid', 'checkout.session.async_payment_succeeded', [
            ...$session,
            'payment_status' => 'paid',
        ], 'acct_test_123')->assertOk();
        $this->postWebhook('evt_async_paid', 'checkout.session.async_payment_succeeded', [
            ...$session,
            'payment_status' => 'paid',
        ], 'acct_test_123')->assertOk();
        $this->postWebhook('evt_fail_late', 'checkout.session.async_payment_failed', [
            ...$session,
            'payment_status' => 'unpaid',
        ], 'acct_test_123')->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'paid');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('paid', Payment::query()->firstOrFail()->status);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'payment.completed')->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'payment.failed')->count());
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_a_deposit_balance_charges_the_remainder_with_no_second_fee(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);

        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'), [
            'deposit_percent' => 50,
        ])->assertOk()
            ->assertJsonPath('data.payload.customer_total_cents', 10_300)
            ->assertJsonPath('data.payload.charge_cents', 5_150)
            ->assertJsonPath('data.payload.application_fee_cents', 300);

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/balance-checkout'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertStatus(409)->assertJsonPath('message', 'This booking has no balance due.');

        $deposit = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk()->json('data');
        $this->assertSame(5_150, $deposit['amount_cents']);
        $this->assertSame(300, $deposit['fee_cents']);

        $this->postWebhook('evt_deposit', 'checkout.session.completed', [
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 5_150,
            'payment_intent' => 'pi_deposit',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment_status', 'deposit_paid');

        $fake->sessionId = 'cs_test_balance';
        $fake->sessionUrl = 'https://checkout.stripe.test/pay/cs_test_balance';
        $balanceBody = [
            'success_url' => 'https://dock.test/balance',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'balance-1',
        ];
        $started = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/balance-checkout'), $balanceBody)
            ->assertOk();
        $this->assertSame(5_150, $started->json('data.amount_cents'));
        $this->assertSame(0, $started->json('data.fee_cents'));
        $this->assertSame('https://checkout.stripe.test/pay/cs_test_balance', $started->json('data.checkout_url'));
        $this->assertArrayNotHasKey('application_fee_amount', $fake->sessions[1]['params']['payment_intent_data']);

        $replay = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/balance-checkout'), $balanceBody)
            ->assertOk();
        $this->assertSame($started->json('data.payment_id'), $replay->json('data.payment_id'));
        $this->assertNull($replay->json('data.checkout_url'));
        $this->assertCount(2, $fake->sessions);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/balance-checkout'), [
            'success_url' => 'https://dock.test/other',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'balance-1',
        ])->assertStatus(409);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/balance-checkout'), [
            'success_url' => 'https://dock.test/balance',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'balance-2',
        ])->assertStatus(409)->assertJsonPath('message', 'A balance checkout is already open.');

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$bookingId.'/balance-checkout'), $balanceBody)
            ->assertNotFound();

        $this->actingAsWorkspace($workspace);
        $this->postWebhook('evt_balance', 'checkout.session.completed', [
            'id' => 'cs_test_balance',
            'payment_status' => 'paid',
            'amount_total' => 5_150,
            'payment_intent' => 'pi_balance',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, PlatformFee::query()->count());
        $this->assertSame(300, PlatformFee::query()->firstOrFail()->amount_cents);
        $this->assertSame(2, Payment::query()->count());
        $balance = Payment::query()->where('kind', 'balance_stripe_payment')->firstOrFail();
        $this->assertSame(0, $balance->fee_cents);
        $this->assertSame(5_150, $balance->amount_cents);
        $this->assertSame('paid', $balance->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.balance_checkout_started')->count());
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_a_reschedule_onto_a_higher_rate_charges_the_increase_only(): void
    {
        Http::fake();
        Mail::fake();
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);

        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($service);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekend',
            'kind' => 'base',
            'amount_cents' => 20_000,
            'window' => 'weekend',
        ])->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/pricing'))
            ->assertOk()
            ->assertJsonPath('data.payload.services_cents', 10_000)
            ->assertJsonPath('data.payload.customer_total_cents', 10_300)
            ->assertJsonPath('data.payload.priced_on', '2026-06-15');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), [
            'success_url' => 'https://dock.test/increase',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertStatus(409)->assertJsonPath('message', 'Only a confirmed booking can be charged for a price increase.');

        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);
        app(TenantManager::class)->forget();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://dock.test/paid',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertOk();
        $this->postWebhook('evt_paid_increase', 'checkout.session.completed', [
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 10_300,
            'payment_intent' => 'pi_original',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), [
            'success_url' => 'https://dock.test/increase',
            'cancel_url' => 'https://dock.test/cancel',
        ])->assertStatus(409)->assertJsonPath('message', 'This booking has no price increase.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/reschedule'), [
            'starts_at' => '2026-06-20T15:00:00Z',
            'ends_at' => '2026-06-20T16:00:00Z',
        ])->assertOk()->assertJsonPath('data.starts_at', '2026-06-20T15:00:00Z');

        $fake->sessionId = 'cs_test_increase';
        $fake->sessionUrl = 'https://checkout.stripe.test/pay/cs_test_increase';
        $increaseBody = [
            'success_url' => 'https://dock.test/increase',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'increase-1',
        ];
        $started = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), $increaseBody)
            ->assertOk();
        $this->assertSame(10_300, $started->json('data.amount_cents'));
        $this->assertSame(300, $started->json('data.fee_cents'));
        $this->assertSame('https://checkout.stripe.test/pay/cs_test_increase', $started->json('data.checkout_url'));
        $this->assertSame(300, $fake->sessions[1]['params']['payment_intent_data']['application_fee_amount']);
        $this->assertSame(10_300, $fake->sessions[1]['params']['line_items'][0]['price_data']['unit_amount']);

        $replay = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), $increaseBody)
            ->assertOk();
        $this->assertSame($started->json('data.payment_id'), $replay->json('data.payment_id'));
        $this->assertNull($replay->json('data.checkout_url'));
        $this->assertCount(2, $fake->sessions);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), [
            'success_url' => 'https://dock.test/other',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'increase-1',
        ])->assertStatus(409);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), [
            'success_url' => 'https://dock.test/increase',
            'cancel_url' => 'https://dock.test/cancel',
            'idempotency_key' => 'increase-2',
        ])->assertStatus(409)->assertJsonPath('message', 'A price increase checkout is already open.');

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/bookings/'.$bookingId.'/increase-checkout'), $increaseBody)
            ->assertNotFound();

        $this->actingAsWorkspace($workspace);
        $this->postWebhook('evt_increase', 'checkout.session.completed', [
            'id' => 'cs_test_increase',
            'payment_status' => 'paid',
            'amount_total' => 10_300,
            'payment_intent' => 'pi_increase',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();
        $this->postWebhook('evt_increase', 'checkout.session.completed', [
            'id' => 'cs_test_increase',
            'payment_status' => 'paid',
            'amount_total' => 10_300,
            'payment_intent' => 'pi_increase',
            'metadata' => ['booking_id' => $bookingId, 'tenant_id' => $workspace['tenant']->id],
        ], 'acct_test_123')->assertOk();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId))
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, PlatformFee::query()->count());
        $this->assertSame(600, (int) PlatformFee::query()->sum('amount_cents'));
        $increase = Payment::query()->where('kind', 'price_increase_stripe_payment')->firstOrFail();
        $this->assertSame(300, $increase->fee_cents);
        $this->assertSame(10_300, $increase->amount_cents);
        $this->assertSame('paid', $increase->status);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(20_000, $snapshot->payload['services_cents']);
        $this->assertSame(20_000, $snapshot->payload['basis_cents']);
        $this->assertSame(600, $snapshot->payload['fee_cents']);
        $this->assertSame(600, $snapshot->payload['customer_fee_line_cents']);
        $this->assertSame(20_600, $snapshot->payload['customer_total_cents']);
        $this->assertSame(300, $snapshot->payload['application_fee_cents']);
        $this->assertSame('2026-06-20', $snapshot->payload['priced_on']);
        $this->assertArrayNotHasKey('pending_increase', $snapshot->payload);
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.increase_checkout_started')->count());
        app(TenantManager::class)->forget();

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_an_unconfigured_webhook_is_rejected(): void
    {
        $this->postJson('http://localhost/api/v1/stripe/webhook', ['id' => 'evt_x'], [
            'Stripe-Signature' => 'test-signature',
        ])->assertStatus(400)->assertJsonPath('message', 'Invalid Stripe signature.');
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function postWebhook(string $id, string $type, array $object, ?string $account = null): TestResponse
    {
        return $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => $id,
            'type' => $type,
            'account' => $account,
            'data' => ['object' => $object],
        ], ['Stripe-Signature' => 'test-signature']);
    }

    private function hold(Service $service, string $suffix = '', string $starts = '2026-06-15T15:00:00Z', string $ends = '2026-06-15T16:00:00Z'): string
    {
        return $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 2,
            'idempotency_key' => 'pay-'.$service->id.$suffix,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertCreated()->json('data.id');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service];
    }
}
