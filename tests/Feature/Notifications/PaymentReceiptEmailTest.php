<?php

namespace Tests\Feature\Notifications;

use App\Domain\Payments\StripeGateway;
use App\Models\Asset;
use App\Models\Domain;
use App\Models\GhlConnection;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Notifications\BookingUpdateNotification;
use App\Services\Payments\FakeStripeGateway;
use App\Support\Display;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PaymentReceiptEmailTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_card_payment_emails_the_stored_receipt_once_and_leaves_the_charge_unchanged(): void
    {
        $paid = $this->payByCard('dock.test', 'ada@example.com', 'Ada');
        $offline = $this->payOffline('pier.test', 'bea@example.com', 'Bea');
        config(['mail.default' => 'smtp']);
        Notification::fake();

        $this->artisan('integrations:dispatch')->assertSuccessful();
        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 2);
        Notification::assertSentOnDemand(
            BookingUpdateNotification::class,
            function (BookingUpdateNotification $mail, array $channels, AnonymousNotifiable $to) use ($paid, $offline): bool {
                if (($to->routes['mail'] ?? null) !== 'ada@example.com') {
                    return false;
                }

                $text = implode("\n", $mail->lines);
                $html = (string) $mail->toMail($to)->render();
                $this->assertSame(['mail'], $channels);
                $this->assertSame('Your receipt from '.$mail->businessName, $mail->subject);
                $this->assertStringContainsString('Card: '.Display::money($paid['payment_cents']).'.', $text);
                $this->assertStringContainsString('Booking fee: '.Display::money($paid['fee_line_cents']).'.', $text);
                $this->assertStringContainsString('Subtotal: '.Display::money($paid['subtotal_cents']).'.', $text);
                $this->assertStringContainsString('Total: '.Display::money($paid['total_cents']).'.', $text);
                $this->assertStringContainsString('Paid: '.Display::money($paid['total_cents']).'.', $text);
                $this->assertStringContainsString('Reference: '.$paid['reference'].'.', $text);
                $this->assertStringContainsString('Mon, Jun 15, 3:00 PM', $text);
                $this->assertStringNotContainsString('11:00 AM', $text);
                $this->assertStringNotContainsString(strtolower('credit card fee'), strtolower($text));
                $this->assertStringNotContainsString('surcharge', strtolower($text));
                $this->assertStringNotContainsString('processing fee', strtolower($text));
                $this->assertStringNotContainsString($offline['reference'], $text);
                $this->assertStringContainsString('name="viewport"', $html);
                $this->assertStringContainsString('width=device-width', $html);
                $this->assertStringContainsString('overflow-wrap:anywhere', $html);
                $this->assertStringContainsString('max-width:100%', $html);
                $this->assertStringNotContainsString('white-space:nowrap', $html);
                $this->assertStringNotContainsString('<script', $html);

                return true;
            },
        );
        Notification::assertSentOnDemand(
            BookingUpdateNotification::class,
            function (BookingUpdateNotification $mail, array $channels, AnonymousNotifiable $to) use ($offline, $paid): bool {
                if (($to->routes['mail'] ?? null) !== 'bea@example.com') {
                    return false;
                }

                $text = implode("\n", $mail->lines);

                return $channels === ['mail']
                    && $to->routes['mail'] === 'bea@example.com'
                    && str_contains($text, 'Paid offline: '.Display::money($offline['payment_cents']).'.')
                    && str_contains($text, 'Booking fee: '.Display::money($offline['fee_line_cents']).'.')
                    && str_contains($text, 'Reference: '.$offline['reference'].'.')
                    && ! str_contains($text, $paid['reference']);
            },
        );

        $this->assertUnchanged($paid);
        $this->assertUnchanged($offline);
        app(TenantManager::class)->set($paid['tenant']);
        $this->assertSame('sent', NotificationHook::query()->where('kind', 'payment.completed')->sole()->delivery_status);
        $this->assertSame(0, Refund::query()->count());
        app(TenantManager::class)->set($offline['tenant']);
        $this->assertSame('sent', NotificationHook::query()->where('kind', 'payment.completed')->sole()->delivery_status);
        $this->assertSame(0, Refund::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_linked_or_switched_off_workspace_does_not_email_and_a_refused_mailer_is_recorded(): void
    {
        $linked = $this->payByCard('dock.test', 'ada@example.com', 'Ada');
        app(TenantManager::class)->set($linked['tenant']);
        GhlConnection::factory()->create([
            'tenant_id' => $linked['tenant']->id,
            'location_id' => 'loc-dock.test',
            'private_integration_token' => 'pit-dock.test',
        ]);
        app(TenantManager::class)->forget();
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $this->artisan('integrations:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 0);
        app(TenantManager::class)->set($linked['tenant']);
        $this->assertSame('Sent through GoHighLevel.', NotificationHook::query()->where('kind', 'payment.completed')->sole()->delivery_error);
        $this->assertUnchanged($linked);

        $quiet = $this->payOffline('pier.test', 'bea@example.com', 'Bea');
        app(TenantManager::class)->set($quiet['tenant']);
        TenantNotificationSetting::query()->updateOrCreate(
            ['tenant_id' => $quiet['tenant']->id],
            ['staff_alert_recipients' => [], 'transactional_emails' => ['payment.completed' => false]],
        );
        app(TenantManager::class)->forget();
        $this->artisan('integrations:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 0);
        app(TenantManager::class)->set($quiet['tenant']);
        $this->assertSame('The workspace turned this message off.', NotificationHook::query()->where('kind', 'payment.completed')->sole()->delivery_error);
        $this->assertUnchanged($quiet);

        $refused = $this->payOffline('cove.test', 'cy@example.com', 'Cy');
        app(TenantManager::class)->forget();
        Mail::extend('refusing', function (): TransportInterface {
            return new class implements TransportInterface
            {
                public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
                {
                    throw new RuntimeException('smtp down');
                }

                public function __toString(): string
                {
                    return 'refusing';
                }
            };
        });
        config([
            'mail.mailers.refusing.transport' => 'refusing',
            'mail.default' => 'refusing',
        ]);
        Notification::swap(new ChannelManager($this->app));
        $this->artisan('integrations:dispatch')->assertSuccessful();
        app(TenantManager::class)->set($refused['tenant']);
        $hook = NotificationHook::query()->where('kind', 'payment.completed')->sole();
        $this->assertSame('failed', $hook->delivery_status);
        $this->assertSame('The mailer refused the message (RuntimeException).', $hook->delivery_error);
        $this->assertUnchanged($refused);
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array{tenant: Tenant, payment_cents: int, fee_cents: int, payload: array<string, mixed>}  $paid
     */
    private function assertUnchanged(array $paid): void
    {
        app(TenantManager::class)->set($paid['tenant']);
        $payment = Payment::query()->sole();
        $this->assertSame($paid['payment_cents'], $payment->amount_cents);
        $this->assertSame($paid['fee_cents'], (int) $payment->fee_cents);
        $this->assertSame($paid['payload'], PricingSnapshot::query()->sole()->payload);
        $fee = PlatformFee::query()->first();
        $this->assertSame($paid['platform_fee_cents'], $fee instanceof PlatformFee ? $fee->amount_cents : null);
    }

    /**
     * @return array{tenant: Tenant, payment_cents: int, fee_cents: int, fee_line_cents: int, subtotal_cents: int, total_cents: int, platform_fee_cents: int|null, payload: array<string, mixed>, reference: string}
     */
    private function payByCard(string $host, string $email, string $name): array
    {
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);
        [$workspace, $service] = $this->rental($host);
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($host, $service, $email, $name);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl($host, '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();
        app(TenantManager::class)->set($workspace['tenant']);
        StripeAccount::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'stripe_account_id' => 'acct_'.$host,
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);

        app(TenantManager::class)->forget();
        $this->postJson($this->tenantUrl($host, '/api/v1/bookings/'.$bookingId.'/checkout-session'), [
            'success_url' => 'https://'.$host.'/paid',
            'cancel_url' => 'https://'.$host.'/cancel',
        ])->assertOk();
        $this->postWebhook('evt_'.$host, [
            'id' => 'cs_test_123',
            'payment_status' => 'paid',
            'amount_total' => 10_300,
            'payment_intent' => 'pi_'.$host,
            'metadata' => [
                'booking_id' => $bookingId,
                'tenant_id' => $workspace['tenant']->id,
            ],
        ], 'acct_'.$host)->assertOk();

        return $this->stored($workspace['tenant'], $bookingId);
    }

    /**
     * @return array{tenant: Tenant, payment_cents: int, fee_cents: int, fee_line_cents: int, subtotal_cents: int, total_cents: int, platform_fee_cents: int|null, payload: array<string, mixed>, reference: string}
     */
    private function payOffline(string $host, string $email, string $name): array
    {
        [$workspace, $service] = $this->rental($host);
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->hold($host, $service, $email, $name);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Base',
            'kind' => 'base',
            'amount_cents' => 10_000,
        ])->assertCreated();
        $this->postJson($this->tenantUrl($host, '/api/v1/bookings/'.$bookingId.'/pricing'))->assertOk();
        $this->postJson($this->tenantUrl($host, '/api/v1/bookings/'.$bookingId.'/offline-payment'))->assertOk();

        return $this->stored($workspace['tenant'], $bookingId);
    }

    /**
     * @return array{tenant: Tenant, payment_cents: int, fee_cents: int, fee_line_cents: int, subtotal_cents: int, total_cents: int, platform_fee_cents: int|null, payload: array<string, mixed>, reference: string}
     */
    private function stored(Tenant $tenant, string $bookingId): array
    {
        app(TenantManager::class)->set($tenant);
        $payment = Payment::query()->where('booking_id', $bookingId)->sole();
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->sole();
        $payload = $snapshot->payload;
        $fee = PlatformFee::query()->where('booking_id', $bookingId)->first();
        $feeLine = $payload['customer_fee_line_cents'] ?? null;
        $subtotal = $this->storedSubtotal($payload);
        $total = $payload['customer_total_cents'] ?? 0;
        app(TenantManager::class)->forget();

        return [
            'tenant' => $tenant,
            'payment_cents' => $payment->amount_cents,
            'fee_cents' => (int) $payment->fee_cents,
            'fee_line_cents' => is_numeric($feeLine) ? (int) $feeLine : 0,
            'subtotal_cents' => is_numeric($subtotal) ? (int) $subtotal : 0,
            'total_cents' => is_numeric($total) ? (int) $total : 0,
            'platform_fee_cents' => $fee instanceof PlatformFee ? $fee->amount_cents : null,
            'payload' => $payload,
            'reference' => strtoupper(substr($bookingId, -8)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storedSubtotal(array $payload): int
    {
        $number = function (string $key) use ($payload): int {
            $value = $payload[$key] ?? 0;

            return is_numeric($value) ? (int) $value : 0;
        };

        if (! array_key_exists('services_cents', $payload)) {
            $operator = 0;
            $lines = $payload['operator_fees'] ?? [];

            if (is_array($lines)) {
                foreach ($lines as $line) {
                    if (is_array($line) && is_numeric($line['total_cents'] ?? null)) {
                        $operator += (int) $line['total_cents'];
                    }
                }
            }

            return max(0, $number('basis_cents') - $operator);
        }

        return max(0, $number('services_cents') - $number('discount_cents') + $number('add_ons_cents'));
    }

    private function hold(string $host, Service $service, string $email, string $name): string
    {
        return $this->postJson($this->tenantUrl($host, '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 2,
            'idempotency_key' => 'receipt-'.$host,
            'customer_name' => $name,
            'customer_email' => $email,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function postWebhook(string $id, array $session, string $account): TestResponse
    {
        return $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => $id,
            'type' => 'checkout.session.completed',
            'account' => $account,
            'data' => ['object' => $session],
        ], ['Stripe-Signature' => 'test-signature']);
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(string $host): array
    {
        $workspace = $this->workspace($host);
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
