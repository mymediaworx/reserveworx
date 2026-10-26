<?php

namespace Tests\Feature\Booking;

use App\Domain\Payments\StripeGateway;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\Settings;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Customer;
use App\Models\Location;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CancellationPolicyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private FakeStripeGateway $stripe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        $this->stripe = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $this->stripe);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_an_owner_saves_windows_and_staff_cannot(): void
    {
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);

        $this->putJson($this->tenantUrl('dock.test', '/api/v1/cancellation-policy'), [
            'customer_cancel' => [
                ['lead_minutes' => 1440, 'refund_percent' => 50],
                ['lead_minutes' => 2880, 'refund_percent' => 100],
            ],
            'no_show_refund_percent' => 25,
        ])->assertOk()
            ->assertJsonPath('data.customer_cancel.0.lead_minutes', 2880)
            ->assertJsonPath('data.customer_cancel.0.refund_percent', 100)
            ->assertJsonPath('data.customer_cancel.1.lead_minutes', 1440)
            ->assertJsonPath('data.customer_cancel.1.refund_percent', 50)
            ->assertJsonPath('data.no_show_refund_percent', 25);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/cancellation-policy'))
            ->assertOk()
            ->assertJsonPath('data.customer_cancel.0.refund_percent', 100);

        $this->putJson($this->tenantUrl('dock.test', '/api/v1/cancellation-policy'), [
            'customer_cancel' => [
                ['lead_minutes' => 60, 'refund_percent' => 100],
                ['lead_minutes' => 60, 'refund_percent' => 0],
            ],
            'no_show_refund_percent' => null,
        ])->assertStatus(422);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(3, CancellationPolicy::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'cancellation_policy.updated')->count());
        app(TenantManager::class)->forget();

        $staff = $this->workspace('crew.test', 'staff');
        $this->actingAsWorkspace($staff);
        $this->putJson($this->tenantUrl('crew.test', '/api/v1/cancellation-policy'), [
            'customer_cancel' => [
                ['lead_minutes' => 60, 'refund_percent' => 100],
            ],
            'no_show_refund_percent' => 100,
        ])->assertForbidden();

        app(TenantManager::class)->set($staff['tenant']);
        $this->actingAs($staff['user']);
        Livewire::test(Settings::class)
            ->set('cancelTiers', [['lead_minutes' => '60', 'refund_percent' => '100']])
            ->set('noShowPercent', '100')
            ->call('saveCancellationPolicy')
            ->assertForbidden();
        $this->assertSame(0, CancellationPolicy::query()->count());

        $owner = $this->workspace('owner.test');
        app(TenantManager::class)->set($owner['tenant']);
        $this->actingAs($owner['user']);
        Livewire::test(Settings::class)
            ->set('cancelTiers', [['lead_minutes' => '2880', 'refund_percent' => '100']])
            ->set('noShowPercent', '25')
            ->call('saveCancellationPolicy')
            ->assertHasNoErrors()
            ->assertSet('flash', 'Cancellation policy saved. It applies the next time a customer cancels or is marked as a no-show.');

        $this->assertSame(100, CancellationPolicy::query()->where('kind', 'customer_cancel')->firstOrFail()->refund_percent);
        $this->assertSame(25, CancellationPolicy::query()->where('kind', 'no_show')->firstOrFail()->refund_percent);
    }

    public function test_a_guest_cancel_refunds_the_matching_window_and_nothing_by_default(): void
    {
        $shop = $this->shop();
        $this->savePolicy($shop, [
            ['lead_minutes' => 2880, 'refund_percent' => 100],
            ['lead_minutes' => 1440, 'refund_percent' => 50],
        ], null);
        $this->openGuestCancel($shop['tenant']);

        $full = $this->paidBooking($shop, '2026-06-17 12:00:00', 10_300, 'stripe', 0);
        $page = $this->manage($shop['token'], $full)->assertOk();
        $page->assertSee('Cancelling refunds the card payment, including the booking fee.');
        $page->assertSee('A cancellation at least 48 hours before the start receives a full refund, including the booking fee.');
        $this->postCancel($page)->assertOk()->assertSee('Status: Cancelled');

        app(TenantManager::class)->set($shop['tenant']);
        $refund = Refund::query()->where('booking_id', $full->id)->firstOrFail();
        $this->assertSame('customer_cancelled_full_refund', $refund->reason);
        $this->assertSame(10_300, $refund->customer_refund_cents);
        $this->assertSame(300, $refund->customer_fee_refund_cents);
        $this->assertTrue($this->stripe->refunds[0]['params']['refund_application_fee']);
        $this->assertSame(10_300, $this->stripe->refunds[0]['params']['amount']);
        $this->assertSame([], $this->stripe->feeRefunds);
        $this->assertSame('cancelled', Booking::query()->findOrFail($full->id)->status->value);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.guest_cancelled')->where('booking_id', $full->id)->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.refunded')->where('booking_id', $full->id)->count());
        app(TenantManager::class)->forget();

        $partial = $this->paidBooking($shop, '2026-06-16 12:00:00', 10_500, 'stripe', 200, 'pi_partial');
        $this->postCancel($this->manage($shop['token'], $partial))->assertOk();

        app(TenantManager::class)->set($shop['tenant']);
        $partialRefund = Refund::query()->where('booking_id', $partial->id)->firstOrFail();
        $this->assertSame('customer_cancelled_partial_refund', $partialRefund->reason);
        $this->assertSame(5_250, $partialRefund->customer_refund_cents);
        $this->assertSame(150, $partialRefund->application_fee_refund_cents);
        $this->assertSame(100, $partialRefund->refund_tax_cents);
        $this->assertSame(5_000, $partialRefund->refund_subtotal_cents);
        $this->assertArrayNotHasKey('refund_application_fee', $this->stripe->refunds[1]['params']);
        $this->assertSame(150, $this->stripe->feeRefunds[0]['amount']);
        app(TenantManager::class)->forget();

        $none = $this->paidBooking($shop, '2026-06-15 18:00:00', 10_300, 'stripe', 0, 'pi_none');
        $nonePage = $this->manage($shop['token'], $none)->assertOk();
        $nonePage->assertSee('Cancelling releases the time. It does not refund a card payment.');
        $this->postCancel($nonePage)->assertOk();
        app(TenantManager::class)->set($shop['tenant']);
        $this->assertSame(0, Refund::query()->where('booking_id', $none->id)->count());
        $this->assertSame('cancelled', Booking::query()->findOrFail($none->id)->status->value);

        $offline = $this->paidBooking($shop, '2026-06-17 15:00:00', 10_300, 'offline', 0, 'offline-1');
        $this->postCancel($this->manage($shop['token'], $offline))->assertOk();
        app(TenantManager::class)->set($shop['tenant']);
        $this->assertSame(0, Refund::query()->where('booking_id', $offline->id)->count());
        $this->assertSame('cancelled', Booking::query()->findOrFail($offline->id)->status->value);
        app(TenantManager::class)->forget();

        $plain = $this->shop('plain.test');
        $this->openGuestCancel($plain['tenant']);
        $unpriced = $this->paidBooking($plain, '2026-06-17 12:00:00', 10_300, 'stripe', 0, 'pi_plain');
        $this->postCancel($this->manage($plain['token'], $unpriced, 'plain.test'))->assertOk();
        app(TenantManager::class)->set($plain['tenant']);
        $this->assertSame(0, Refund::query()->where('booking_id', $unpriced->id)->count());
        $this->assertSame('cancelled', Booking::query()->findOrFail($unpriced->id)->status->value);
        app(TenantManager::class)->forget();

        $beta = $this->workspace('beta.test');
        $this->actingAsWorkspace($beta);
        $this->putJson($this->tenantUrl('beta.test', '/api/v1/cancellation-policy'), [
            'customer_cancel' => [
                ['lead_minutes' => 0, 'refund_percent' => 100],
            ],
            'no_show_refund_percent' => 100,
        ])->assertOk();
        $foreign = $this->paidBooking($plain, '2026-06-17 18:00:00', 10_300, 'stripe', 0, 'pi_foreign');
        $this->postCancel($this->manage($plain['token'], $foreign, 'plain.test'))->assertOk();
        app(TenantManager::class)->set($plain['tenant']);
        $this->assertSame(0, Refund::query()->where('booking_id', $foreign->id)->count());
        $this->assertSame('cancelled', Booking::query()->findOrFail($foreign->id)->status->value);
    }

    public function test_a_card_refund_that_cannot_be_issued_leaves_the_booking_in_place(): void
    {
        $shop = $this->shop();
        $this->savePolicy($shop, [
            ['lead_minutes' => 1440, 'refund_percent' => 100],
            ['lead_minutes' => 60, 'refund_percent' => 50],
        ], null);
        $this->openGuestCancel($shop['tenant']);

        $this->app->forgetInstance(StripeGateway::class);
        $blocked = $this->paidBooking($shop, '2026-06-16 13:00:00', 10_300, 'stripe', 0, 'pi_blocked');
        $this->postCancel($this->manage($shop['token'], $blocked))
            ->assertStatus(409)
            ->assertSee('This booking was not cancelled: Stripe is not configured');
        app(TenantManager::class)->set($shop['tenant']);
        $this->assertSame('confirmed', Booking::query()->findOrFail($blocked->id)->status->value);
        $this->assertSame(0, Refund::query()->where('booking_id', $blocked->id)->count());
        app(TenantManager::class)->forget();

        $this->app->instance(StripeGateway::class, $this->stripe);
        $deposit = $this->paidBooking($shop, '2026-06-15 14:00:00', 2_000, 'stripe', 0, 'pi_deposit');
        $this->postCancel($this->manage($shop['token'], $deposit))
            ->assertStatus(409)
            ->assertSee('This booking was not cancelled: Customer refund cannot exceed the amount paid and not yet refunded.');
        app(TenantManager::class)->set($shop['tenant']);
        $this->assertSame('confirmed', Booking::query()->findOrFail($deposit->id)->status->value);
        $this->assertSame(0, Refund::query()->where('booking_id', $deposit->id)->count());

        $done = $this->paidBooking($shop, '2026-06-16 18:00:00', 10_300, 'stripe', 0, 'pi_done');
        $this->actingAsWorkspace($shop);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$done->id.'/refunds'), [
            'reason' => 'operator_cancelled',
            'idempotency_key' => 'already-refunded',
        ])->assertCreated();
        $this->postCancel($this->manage($shop['token'], $done))->assertOk();
        app(TenantManager::class)->set($shop['tenant']);
        $this->assertSame('cancelled', Booking::query()->findOrFail($done->id)->status->value);
        $this->assertSame(1, Refund::query()->where('booking_id', $done->id)->count());
    }

    public function test_a_no_show_follows_the_saved_percent(): void
    {
        $shop = $this->shop();
        $untouched = $this->paidBooking($shop, '2026-06-16 15:00:00', 10_300, 'stripe', 0, 'pi_show');
        $this->actingAsWorkspace($shop);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$untouched->id.'/no-show'))->assertOk();

        app(TenantManager::class)->set($shop['tenant']);
        $this->assertSame('no_show', Booking::query()->findOrFail($untouched->id)->status->value);
        $this->assertSame(0, Refund::query()->where('booking_id', $untouched->id)->count());

        $this->savePolicy($shop, [], 50);
        $partial = $this->paidBooking($shop, '2026-06-16 16:00:00', 10_300, 'stripe', 0, 'pi_noshow');
        $this->actingAsWorkspace($shop);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$partial->id.'/no-show'))
            ->assertOk()
            ->assertJsonPath('data.status', 'no_show');
        app(TenantManager::class)->set($shop['tenant']);
        $refund = Refund::query()->where('booking_id', $partial->id)->firstOrFail();
        $this->assertSame('no_show_partial_refund', $refund->reason);
        $this->assertSame(5_150, $refund->customer_refund_cents);
        $this->assertSame(150, $refund->application_fee_refund_cents);

        $this->savePolicy($shop, [], 100);
        $full = $this->paidBooking($shop, '2026-06-16 17:00:00', 10_300, 'stripe', 0, 'pi_staff_show');
        app(TenantManager::class)->set($shop['tenant']);
        $this->actingAs($shop['user']);
        Livewire::test(BookingShow::class, ['booking' => $full->id])
            ->call('markNoShow')
            ->assertSet('flash', 'Marked as a no-show.');
        $this->assertSame('no_show', Booking::query()->findOrFail($full->id)->status->value);
        $this->assertSame('no_show_full_refund', Refund::query()->where('booking_id', $full->id)->value('reason'));
    }

    public function test_the_review_page_states_the_saved_windows(): void
    {
        $shop = $this->shop();
        $this->savePolicy($shop, [
            ['lead_minutes' => 2880, 'refund_percent' => 100],
        ], 0);
        $this->actingAsWorkspace($shop);
        $response = $this->post($this->tenantUrl('dock.test', '/book/'.$shop['token'].'/services/'.$shop['service']->id), [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'review-window',
        ])->assertRedirect();

        $this->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertSee('A cancellation at least 48 hours before the start receives a full refund, including the booking fee.')
            ->assertSee('A no-show receives no refund.');
    }

    /**
     * @param  list<array{lead_minutes: int, refund_percent: int}>  $tiers
     * @param  array{tenant: Tenant, user: User, service: Service, token: string}  $shop
     */
    private function savePolicy(array $shop, array $tiers, ?int $noShow): void
    {
        $this->actingAsWorkspace($shop);
        $this->putJson($this->tenantUrl('dock.test', '/api/v1/cancellation-policy'), [
            'customer_cancel' => $tiers,
            'no_show_refund_percent' => $noShow,
        ])->assertOk();
    }

    /**
     * @param  array{tenant: Tenant}  $tenant
     */
    private function openGuestCancel(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
        TenantSetting::query()->update(['guest_cancel_before_minutes' => 60]);
        app(TenantManager::class)->forget();
    }

    private function manage(string $token, Booking $booking, string $host = 'dock.test'): TestResponse
    {
        $found = $this->post($this->tenantUrl($host, '/book/'.$token.'/lookup'), [
            'reference' => $booking->id,
            'email' => 'ada@example.com',
        ])->assertRedirect();

        return $this->get((string) $found->headers->get('Location'));
    }

    private function postCancel(TestResponse $page): TestResponse
    {
        preg_match('/action="([^"]*cancel[^"]*)"/', (string) $page->getContent(), $cancel);
        $this->assertArrayHasKey(1, $cancel);

        return $this->post(html_entity_decode($cancel[1]));
    }

    /**
     * @param  array{tenant: Tenant, location: Location, service: Service, asset: Asset}  $shop
     */
    private function paidBooking(array $shop, string $startsAt, int $amountCents, string $method, int $taxCents, string $intent = 'pi_policy'): Booking
    {
        app(TenantManager::class)->set($shop['tenant']);
        $customer = Customer::query()->create([
            'tenant_id' => $shop['tenant']->id,
            'name' => 'Ada',
            'email' => 'ada@example.com',
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $shop['tenant']->id,
            'location_id' => $shop['location']->id,
            'service_id' => $shop['service']->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => $method === 'offline' ? PaymentStatus::OfflinePaid : PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt, 'UTC')->addHour()->format('Y-m-d H:i:s'),
            'participants' => 1,
            'payload_hash' => hash('sha256', $intent.$startsAt),
        ]);
        $fee = $method === 'stripe' ? 300 : 0;
        PricingSnapshot::query()->create([
            'tenant_id' => $shop['tenant']->id,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'payload' => [
                'fee_payer' => 'customer_paid',
                'basis_cents' => 10_000,
                'fee_cents' => $fee,
                'application_fee_cents' => $fee,
                'tax_cents' => $taxCents,
                'customer_total_cents' => 10_000 + $taxCents + $fee,
            ],
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $shop['tenant']->id,
            'booking_id' => $booking->id,
            'method' => $method,
            'kind' => $method === 'stripe' ? 'first_stripe_payment' : 'offline',
            'status' => 'paid',
            'amount_cents' => $amountCents,
            'fee_cents' => $fee,
            'stripe_payment_intent_id' => $method === 'stripe' ? $intent : null,
        ]);

        if ($method === 'stripe') {
            PlatformFee::query()->create([
                'tenant_id' => $shop['tenant']->id,
                'booking_id' => $booking->id,
                'payment_id' => $payment->id,
                'amount_cents' => $fee,
                'refunded_cents' => 0,
                'stripe_fee_id' => 'fee_'.$intent,
                'channel' => 'direct',
            ]);
            $account = StripeAccount::withoutGlobalScope(TenantScope::class)->where('tenant_id', $shop['tenant']->id)->first();

            if (! $account instanceof StripeAccount) {
                StripeAccount::query()->create([
                    'tenant_id' => $shop['tenant']->id,
                    'stripe_account_id' => 'acct_'.substr($shop['tenant']->id, 0, 20),
                    'card_payments_status' => 'active',
                    'livemode' => false,
                ]);
            }
        }

        app(TenantManager::class)->forget();

        return $booking;
    }

    /**
     * @return array{tenant: Tenant, user: User, service: Service, location: Location, asset: Asset, token: string}
     */
    private function shop(string $host = 'dock.test'): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => ServiceKind::Rental,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $token = $this->postJson($this->tenantUrl($host, '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        return [
            'tenant' => $workspace['tenant'],
            'user' => $workspace['user'],
            'service' => $service,
            'location' => $location,
            'asset' => $asset,
            'token' => $token,
        ];
    }
}
