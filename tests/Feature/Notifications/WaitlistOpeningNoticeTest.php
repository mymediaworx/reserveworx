<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Jobs\RecordWaitlistPromotion;
use App\Jobs\SendGhlBookingMessage;
use App\Jobs\SyncBookingToGoHighLevel;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GhlConnection;
use App\Models\GhlMessageLog;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\NotificationHook;
use App\Models\PricingSnapshot;
use App\Models\Service;
use App\Models\TenantNotificationSetting;
use App\Notifications\BookingUpdateNotification;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class WaitlistOpeningNoticeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_unlinked_workspace_emails_the_waitlist_opening_once(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $booking = $this->heldBooking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', 'Kayak rental', 'America/New_York', '2026-07-04 14:00:00');
        $other = $this->heldBooking($beta['tenant']->id, 'Bea Other', 'bea@example.com', 'Other rental', 'America/New_York', '2026-07-04 14:00:00');
        $snapshot = $this->snapshot($booking);
        $money = $this->moneyCounts();

        $this->promote($alpha['tenant']->id, $booking->id, 'entry-alpha');
        $this->promote($alpha['tenant']->id, $booking->id, 'entry-alpha');
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();
        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 1);
        Notification::assertSentOnDemand(
            BookingUpdateNotification::class,
            function (BookingUpdateNotification $mail, array $channels, AnonymousNotifiable $to) use ($booking, $other): bool {
                $body = implode("\n", $mail->lines);

                return $to->routes['mail'] === 'ada@example.com'
                    && $mail->subject === 'A spot opened with '.$mail->businessName
                    && str_contains($body, 'A spot opened for Kayak rental on Sat, Jul 4, 10:00 AM.')
                    && str_contains($body, 'The booking is held for you.')
                    && str_contains($body, 'Reference: '.strtoupper(substr($booking->id, -8)).'.')
                    && ! str_contains($body, '$')
                    && ! str_contains($body, '50.00')
                    && ! str_contains(strtolower($body), 'fee')
                    && ! str_contains(strtolower($body), 'tax')
                    && ! str_contains(strtolower($body), 'refund')
                    && ! str_contains($body, $other->id)
                    && ! str_contains($body, 'bea@example.com');
            },
        );

        app(TenantManager::class)->set($alpha['tenant']);
        $hook = NotificationHook::query()->where('kind', 'waitlist.promoted')->sole();
        $this->assertSame('sent', $hook->delivery_status);
        $this->assertNull($hook->delivery_error);
        $this->assertSame('waitlist.promoted:entry-alpha', $hook->dedupe_key);
        $this->assertSame(0, GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $alpha['tenant']->id)->count());
        $this->assertSame($money, $this->moneyCounts());
        $this->assertSame($snapshot, $this->storedPrice($booking));
    }

    public function test_a_turned_off_opening_is_skipped_and_a_refusing_mailer_is_recorded(): void
    {
        Bus::fake([SyncBookingToGoHighLevel::class]);
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $quiet = $this->workspace('quiet.test');
        $booking = $this->heldBooking($quiet['tenant']->id, 'Ada Lovelace', 'ada@example.com', 'Kayak rental', 'UTC', '2026-07-04 14:00:00');
        app(TenantManager::class)->set($quiet['tenant']);
        TenantNotificationSetting::query()->updateOrCreate(
            ['tenant_id' => $quiet['tenant']->id],
            ['staff_alert_recipients' => [], 'transactional_emails' => ['waitlist.promoted' => false]],
        );
        $money = $this->moneyCounts();

        $this->promote($quiet['tenant']->id, $booking->id, 'entry-quiet');
        app(TenantManager::class)->forget();
        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 0);
        app(TenantManager::class)->set($quiet['tenant']);
        $this->assertSame('The workspace turned this message off.', NotificationHook::query()->where('kind', 'waitlist.promoted')->sole()->delivery_error);
        $this->assertSame($money, $this->moneyCounts());

        $linked = $this->workspace('linked.test');
        $this->link($linked['tenant']->id, 'loc_linked', 'pit-linked-DO-NOT-LEAK-aabbccdd');
        $held = $this->heldBooking($linked['tenant']->id, 'Ada Lovelace', 'ada@example.com', 'Kayak rental', 'UTC', '2026-07-04 14:00:00');
        app(TenantManager::class)->set($linked['tenant']);
        $setting = TenantNotificationSetting::query()->firstOrFail();
        $setting->transactional_emails = ['waitlist.promoted' => false];
        $setting->save();
        Http::preventStrayRequests();
        Http::fake();

        $this->promote($linked['tenant']->id, $held->id, 'entry-linked-off');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertDatabaseHas('ghl_message_logs', [
            'booking_id' => $held->id,
            'kind' => 'waitlist.promoted',
            'status' => 'skipped',
            'error' => 'The workspace turned this message off.',
        ]);
        $this->assertSame($money, $this->moneyCounts());

        $refused = $this->workspace('refused.test');
        $refusedBooking = $this->heldBooking($refused['tenant']->id, 'Cy', 'cy@example.com', 'Kayak rental', 'UTC', '2026-07-04 14:00:00');
        $this->promote($refused['tenant']->id, $refusedBooking->id, 'entry-refused');
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
        $hook = NotificationHook::query()->where('kind', 'waitlist.promoted')->sole();
        $this->assertSame('failed', $hook->delivery_status);
        $this->assertSame('The mailer refused the message (RuntimeException).', $hook->delivery_error);
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_a_linked_workspace_sends_the_opening_once_through_its_location(): void
    {
        Bus::fake([SyncBookingToGoHighLevel::class]);
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaToken = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $booking = $this->heldBooking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', 'Kayak rental', 'America/New_York', '2026-06-16 15:00:00');
        $other = $this->heldBooking($beta['tenant']->id, 'Bea Other', 'bea@example.com', 'Other rental', 'America/New_York', '2026-06-16 15:00:00');
        $snapshot = $this->snapshot($booking);
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        $money = $this->moneyCounts();

        $this->promote($beta['tenant']->id, $other->id, 'entry-beta');
        $this->assertSame(0, GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $alpha['tenant']->id)->count());

        $this->link($alpha['tenant']->id, 'loc_alpha', $alphaToken);
        $this->promote($alpha['tenant']->id, $booking->id, 'entry-alpha');
        $this->promote($alpha['tenant']->id, $booking->id, 'entry-alpha');

        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $alpha['tenant']->id)->sole();
        $this->assertSame('waitlist.promoted', $log->kind);
        $this->assertSame('waitlist.promoted:entry-alpha', $log->dedupe_key);
        $this->assertSame('queued', $log->status);
        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%'.$log->id.'%')->count());
        $payload = (string) DB::table('jobs')->where('payload', 'like', '%'.$log->id.'%')->value('payload');
        $this->assertStringNotContainsString($alphaToken, $payload);
        $this->assertSame($money, $this->moneyCounts());

        $this->fakeGhl('loc_alpha', 'msg-opened');
        app()->call([(new SendGhlBookingMessage($alpha['tenant']->id, $log->id)), 'handle']);

        Http::assertSent(function (Request $request) use ($alphaToken, $booking): bool {
            $message = (string) ($request->data()['message'] ?? '');

            return $request->url() === 'https://services.leadconnectorhq.com/conversations/messages'
                && $request->hasHeader('Authorization', 'Bearer '.$alphaToken)
                && $request->data()['type'] === 'Email'
                && $request->data()['emailTo'] === 'ada@example.com'
                && $request->data()['subject'] === 'Waitlist opening: Kayak rental'
                && str_contains($message, 'A spot opened for Kayak rental on Jun 16, 2026 11:00 AM EDT.')
                && str_contains($message, 'The booking is held for you.')
                && str_contains($message, 'Booking '.$booking->id)
                && ! str_contains($message, '$')
                && ! str_contains($message, '50.00')
                && ! str_contains(strtolower($message), 'fee')
                && ! str_contains(strtolower($message), 'tax')
                && ! str_contains(strtolower($message), 'refund')
                && ! str_contains($message, $alphaToken);
        });
        Http::assertNotSent(function (Request $request) use ($betaToken, $other): bool {
            $raw = $request->url().' '.$request->body();

            return str_contains($raw, $betaToken)
                || str_contains($raw, 'loc_beta')
                || str_contains($raw, 'bea@example.com')
                || str_contains($raw, $other->id);
        });

        $log->refresh();
        $this->assertSame('succeeded', $log->status);
        $this->assertSame('email', $log->channel);
        $this->assertSame('msg-opened', $log->ghl_message_id);
        $betaLog = GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $beta['tenant']->id)->sole();
        $this->assertSame('queued', $betaLog->status);
        $this->assertSame($money, $this->moneyCounts());
        $this->assertSame($snapshot, $this->storedPrice($booking));
        $this->assertSame('unpaid', $booking->fresh()->payment_status->value);
        $this->assertSame('held', $booking->fresh()->status->value);

        app(TenantManager::class)->forget();
        $this->artisan('integrations:dispatch')->assertSuccessful();
        Notification::assertNothingSent();
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('Sent through GoHighLevel.', NotificationHook::query()->where('kind', 'waitlist.promoted')->sole()->delivery_error);
    }

    public function test_a_confirmed_booking_is_no_longer_due_for_the_opening_message(): void
    {
        Bus::fake([SyncBookingToGoHighLevel::class]);
        $alpha = $this->workspace('alpha.test');
        $booking = $this->heldBooking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', 'Kayak rental', 'UTC', '2026-06-16 15:00:00');
        $this->link($alpha['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        $this->promote($alpha['tenant']->id, $booking->id, 'entry-confirmed');
        $booking->status = BookingStatus::Confirmed;
        $booking->save();
        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('booking_id', $booking->id)->sole();
        Http::preventStrayRequests();
        Http::fake();

        app()->call([(new SendGhlBookingMessage($alpha['tenant']->id, $log->id)), 'handle']);

        Http::assertNothingSent();
        $log->refresh();
        $this->assertSame('skipped', $log->status);
        $this->assertSame('This message is no longer due.', $log->error);
    }

    /**
     * @return array<string, int>
     */
    private function moneyCounts(): array
    {
        return [
            'price_rules' => DB::table('price_rules')->count(),
            'payments' => DB::table('payments')->count(),
            'platform_fees' => DB::table('platform_fees')->count(),
            'refunds' => DB::table('refunds')->count(),
            'platform_fee_refunds' => DB::table('platform_fee_refunds')->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Booking $booking): array
    {
        $payload = [
            'customer_fee_line_cents' => 200,
            'customer_total_cents' => 5000,
            'tax_cents' => 300,
        ];
        PricingSnapshot::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'v1',
            'payload' => $payload,
        ]);

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedPrice(Booking $booking): array
    {
        $payload = PricingSnapshot::withoutGlobalScope(TenantScope::class)
            ->where('booking_id', $booking->id)
            ->sole()
            ->payload;
        ksort($payload);

        return $payload;
    }

    private function promote(string $tenantId, string $bookingId, string $entryId): void
    {
        app()->call([(new RecordWaitlistPromotion($tenantId, $bookingId, $entryId)), 'handle']);
    }

    private function link(string $tenantId, string $locationId, string $token): void
    {
        GhlConnection::factory()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'private_integration_token' => $token,
        ]);
    }

    private function heldBooking(string $tenantId, string $name, string $email, string $serviceName, string $timezone, string $startsAt): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => $timezone,
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => $serviceName,
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => true,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Held,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt, 'UTC')->addHour(),
            'participants' => 1,
            'payload_hash' => hash('sha256', $tenantId.$serviceName.$email.$startsAt),
        ]);
    }

    private function fakeGhl(string $locationId, string $messageId): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'new' => true,
                'contact' => ['id' => 'contact-1', 'locationId' => $locationId],
            ], 200),
            'https://services.leadconnectorhq.com/conversations/messages' => Http::response([
                'conversationId' => 'conv-1',
                'messageId' => $messageId,
            ], 201),
            'https://services.leadconnectorhq.com/*' => Http::response(['ok' => true], 200),
        ]);
    }
}
