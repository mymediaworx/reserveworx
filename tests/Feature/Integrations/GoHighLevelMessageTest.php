<?php

namespace Tests\Feature\Integrations;

use App\Domain\Payments\StripeGateway;
use App\Enums\BookingStatus;
use App\Enums\GhlMessageKind;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Events\BookingReminderDue;
use App\Jobs\SendGhlBookingMessage;
use App\Jobs\SyncBookingToGoHighLevel;
use App\Listeners\SendBookingReminder;
use App\Livewire\Platform\TenantDetail;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\Customer;
use App\Models\GhlConnection;
use App\Models\GhlMessageLog;
use App\Models\Location;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Notifications\BookingConfirmedNotification;
use App\Services\Integrations\GhlMessages;
use App\Services\Integrations\GhlRequestFailed;
use App\Services\Notifications\BookingEvents;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GoHighLevelMessageTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_settings_form_stores_the_token_encrypted_and_does_not_log_it(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-form-DO-NOT-LEAK-9f3c2a7b1e04';
        $leaks = $this->listenFor($token);
        $this->actingAs($admin);

        Livewire::test(TenantDetail::class, ['tenant' => $workspace['tenant']->id])
            ->set('ghlLocationId', 'not valid')
            ->set('ghlToken', 'pit-too-short')
            ->call('saveGhl')
            ->assertHasErrors(['ghlLocationId', 'ghlToken'])
            ->assertSet('ghlToken', '')
            ->assertDontSee('pit-too-short');

        $this->assertDatabaseMissing('ghl_connections', ['tenant_id' => $workspace['tenant']->id]);

        Livewire::test(TenantDetail::class, ['tenant' => $workspace['tenant']->id])
            ->set('ghlLocationId', 'loc_alpha')
            ->set('ghlToken', $token)
            ->call('saveGhl')
            ->assertHasNoErrors()
            ->assertSet('ghlToken', '')
            ->assertSee('loc_alpha')
            ->assertDontSee($token);

        $connection = GhlConnection::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame($token, $connection->private_integration_token);
        $this->assertStringNotContainsString($token, (string) DB::table('ghl_connections')->get()->toJson());

        $audit = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'platform.ghl_linked')->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertTrue($audit->properties['token_updated']);
        $this->assertStringNotContainsString($token, (string) json_encode($audit->properties));

        $page = $this->get('http://app.reserveworx.com/platform/tenants/'.$workspace['tenant']->id);
        $page->assertOk()->assertSee('Private Integration Token')->assertSee('loc_alpha')->assertDontSee($token);

        Livewire::test(TenantDetail::class, ['tenant' => $workspace['tenant']->id])
            ->set('ghlLocationId', 'loc_beta')
            ->set('ghlToken', '')
            ->call('saveGhl')
            ->assertHasNoErrors()
            ->assertDontSee($token);

        $connection->refresh();
        $this->assertSame('loc_beta', $connection->location_id);
        $this->assertSame($token, $connection->private_integration_token);

        Http::preventStrayRequests();
        Http::fake([
            'https://services.leadconnectorhq.com/locations/loc_beta' => Http::response(['message' => $token], 401),
        ]);

        Livewire::test(TenantDetail::class, ['tenant' => $workspace['tenant']->id])
            ->call('testGhl')
            ->assertSee('GoHighLevel refused the connection.')
            ->assertDontSee($token);

        $this->assertStringNotContainsString($token, (string) DB::table('ghl_sync_logs')->get()->toJson());

        Livewire::test(TenantDetail::class, ['tenant' => $workspace['tenant']->id])
            ->call('removeGhl')
            ->assertSee('Not linked');

        $this->assertDatabaseMissing('ghl_connections', ['tenant_id' => $workspace['tenant']->id]);
        $this->assertFalse($leaks());

        $this->actingAs($workspace['user']);
        Livewire::test(TenantDetail::class, ['tenant' => $workspace['tenant']->id])->assertForbidden();
    }

    public function test_a_linked_confirmation_is_queued_and_not_also_emailed(): void
    {
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $booking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Sunset rental');
        app(TenantManager::class)->set($workspace['tenant']);

        app(BookingEvents::class)->confirmed($booking);
        Notification::assertSentOnDemand(BookingConfirmedNotification::class);
        $this->assertSame(0, GhlMessageLog::withoutGlobalScope(TenantScope::class)->count());

        Notification::fake();
        $this->link($workspace['tenant']->id, 'loc_alpha', $token);
        app(BookingEvents::class)->confirmed($booking);

        Notification::assertNothingSent();
        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame('queued', $log->status);
        $this->assertSame('booking.confirmed:'.$booking->id, $log->dedupe_key);
        $payload = (string) DB::table('jobs')->value('payload');
        $decoded = json_decode($payload, true);
        $this->assertIsArray($decoded);
        $this->assertSame(SendGhlBookingMessage::class, $decoded['displayName'] ?? null);
        $this->assertStringNotContainsString($token, $payload);
        $this->assertStringNotContainsString($token, serialize(new SendGhlBookingMessage($workspace['tenant']->id, $log->id)));
        $this->assertSame(1, DB::table('jobs')->count());

        app(BookingEvents::class)->confirmed($booking);
        $this->assertSame(1, GhlMessageLog::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(1, DB::table('jobs')->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_job_sends_email_to_that_workspaces_location_only(): void
    {
        Bus::fake([SyncBookingToGoHighLevel::class]);
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaToken = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $leaks = $this->listenFor($alphaToken);
        $this->link($alpha['tenant']->id, 'loc_alpha', $alphaToken);
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        $booking = $this->booking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Sunset rental');
        $betaBooking = $this->booking($beta['tenant']->id, 'Bea Other', 'bea@example.com', '312-555-0199');
        $this->fakeGhl('loc_alpha', 'msg-confirmed');
        app(TenantManager::class)->set($alpha['tenant']);
        app(BookingEvents::class)->confirmed($booking);
        app(TenantManager::class)->forget();

        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $job = new SendGhlBookingMessage($alpha['tenant']->id, $log->id);
        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600, 10800], $job->backoff());
        $this->assertSame('database', $job->connection);
        app()->call([$job, 'handle']);

        Http::assertSent(function (Request $request) use ($alphaToken): bool {
            return $request->url() === 'https://services.leadconnectorhq.com/conversations/messages'
                && $request->hasHeader('Authorization', 'Bearer '.$alphaToken)
                && $request->hasHeader('Version', '2021-07-28')
                && $request->data()['type'] === 'Email'
                && $request->data()['status'] === 'pending'
                && $request->data()['contactId'] === 'contact-1'
                && $request->data()['emailTo'] === 'ada@example.com'
                && $request->data()['subject'] === 'Confirmation: Sunset rental'
                && str_contains((string) $request->data()['message'], 'Sunset rental')
                && str_contains((string) $request->data()['message'], $request->data()['message'] !== '' ? 'confirmed' : '');
        });
        Http::assertSent(function (Request $request): bool {
            return str_contains($request->url(), '/contacts/upsert')
                && $request->data()['locationId'] === 'loc_alpha'
                && $request->data()['email'] === 'ada@example.com';
        });
        Http::assertNotSent(function (Request $request) use ($betaToken): bool {
            $raw = $request->url().' '.$request->body();

            return str_contains($raw, $betaToken)
                || str_contains($raw, 'loc_beta')
                || str_contains($raw, 'bea@example.com');
        });

        $log->refresh();
        $this->assertSame('succeeded', $log->status);
        $this->assertSame('email', $log->channel);
        $this->assertSame('msg-confirmed', $log->ghl_message_id);
        $this->assertSame('loc_alpha', $log->location_id);
        $this->assertNull($log->error);
        $this->assertStringNotContainsString($alphaToken, (string) DB::table('ghl_message_logs')->get()->toJson());

        Http::fake();
        $foreign = GhlMessageLog::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $betaBooking->id,
            'kind' => 'booking.confirmed',
            'dedupe_key' => 'booking.confirmed:foreign',
            'status' => 'queued',
        ]);
        app()->call([new SendGhlBookingMessage($alpha['tenant']->id, $foreign->id), 'handle']);
        Http::assertNothingSent();
        $foreign->refresh();
        $this->assertSame('failed', $foreign->status);
        $this->assertSame('Booking does not belong to this workspace.', $foreign->error);
        $this->assertStringNotContainsString('bea@example.com', (string) $foreign->error);
        $this->assertStringNotContainsString($betaToken, (string) DB::table('ghl_message_logs')->get()->toJson());
        $this->assertFalse($leaks());
    }

    public function test_a_phone_only_customer_gets_an_sms_and_a_rate_limit_is_retried(): void
    {
        Bus::fake([SyncBookingToGoHighLevel::class]);
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $leaks = $this->listenFor($token);
        $this->link($workspace['tenant']->id, 'loc_alpha', $token);
        $booking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', null, '312-555-0100', 'Sunset rental');
        app(TenantManager::class)->set($workspace['tenant']);
        app(BookingEvents::class)->confirmed($booking);
        app(TenantManager::class)->forget();
        $this->fakeGhl('loc_alpha', 'msg-sms');

        app()->call([new SendGhlBookingMessage(
            $workspace['tenant']->id,
            GhlMessageLog::withoutGlobalScope(TenantScope::class)->firstOrFail()->id,
        ), 'handle']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://services.leadconnectorhq.com/conversations/messages'
                && $request->data()['type'] === 'SMS'
                && $request->data()['toNumber'] === '312-555-0100'
                && $request->data()['status'] === 'pending'
                && ! array_key_exists('emailTo', $request->data());
        });

        $limited = $this->booking($workspace['tenant']->id, 'Grace Hopper', 'grace@example.com', null, 'Morning rental');
        app(TenantManager::class)->set($workspace['tenant']);
        app(BookingEvents::class)->confirmed($limited);
        app(TenantManager::class)->forget();
        $this->replaceHttpFake([
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'contact' => ['id' => 'contact-1', 'locationId' => 'loc_alpha'],
            ], 200),
            'https://services.leadconnectorhq.com/conversations/messages' => Http::response(['message' => $token], 429),
        ]);
        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('booking_id', $limited->id)->firstOrFail();

        try {
            app()->call([new SendGhlBookingMessage($workspace['tenant']->id, $log->id), 'handle']);
            $this->fail('The rate limit was not raised for the queue to retry.');
        } catch (GhlRequestFailed $exception) {
            $this->assertSame('GoHighLevel message returned HTTP 429.', $exception->getMessage());
            $this->assertStringNotContainsString($token, $exception->getMessage());
        }

        $log->refresh();
        $this->assertSame('failed', $log->status);
        $this->assertSame(429, $log->http_status);
        $this->assertSame(1, $log->attempts);
        $this->assertStringNotContainsString($token, (string) $log->error);
        $this->assertDatabaseHas('bookings', ['id' => $limited->id]);
        $this->assertFalse($leaks());
    }

    public function test_a_turned_off_message_is_skipped_and_other_communications_are_sent(): void
    {
        Bus::fake([SyncBookingToGoHighLevel::class]);
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $this->link($workspace['tenant']->id, 'loc_alpha', $token);
        app(TenantManager::class)->set($workspace['tenant']);
        $setting = TenantNotificationSetting::query()->firstOrFail();
        $setting->transactional_emails = ['booking.confirmed' => false];
        $setting->save();
        $quiet = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');
        Http::preventStrayRequests();
        Http::fake();

        app(BookingEvents::class)->confirmed($quiet);

        Notification::assertNothingSent();
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertDatabaseHas('ghl_message_logs', [
            'booking_id' => $quiet->id,
            'status' => 'skipped',
            'error' => 'The workspace turned this message off.',
        ]);

        $setting->transactional_emails = ['booking.confirmed' => true];
        $setting->save();
        $booking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Sunset rental');
        $booking->status = BookingStatus::Cancelled;
        $booking->save();
        app(BookingEvents::class)->cancelled($booking);
        app(GhlMessages::class)->queueBooking($booking, GhlMessageKind::GuestCancelled);
        $payment = Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 5000,
            'fee_cents' => 0,
        ]);
        PricingSnapshot::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0',
            'payload' => [
                'customer_fee_line_cents' => 300,
                'tax_cents' => 200,
                'customer_total_cents' => 5500,
            ],
        ]);
        app(BookingEvents::class)->paymentCompleted($payment);
        $checkout = RentalCheckout::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'quantity' => 1,
            'checked_out_at' => '2026-06-16 15:00:00',
            'due_at' => '2026-06-16 16:00:00',
        ]);
        app(BookingEvents::class)->overdue($checkout);
        $reminderBooking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', null, 'Reminder rental');
        $reminderBooking->forceFill([
            'starts_at' => now()->addDays(2),
            'ends_at' => now()->addDays(2)->addHour(),
        ])->save();
        BookingReminder::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $reminderBooking->id,
            'offset_minutes' => 120,
        ]);
        app(SendBookingReminder::class)->handle(new BookingReminderDue($workspace['tenant']->id, $reminderBooking->id, 120));
        app(TenantManager::class)->forget();

        $this->fakeGhl('loc_alpha', 'msg-many');
        $logs = GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('status', 'queued')->get();
        $this->assertSame([
            'booking.cancelled',
            'booking.guest_cancelled',
            'payment.receipt',
            'rental.overdue',
            'booking.reminder',
        ], $logs->pluck('kind')->all());

        foreach ($logs as $log) {
            app()->call([new SendGhlBookingMessage($workspace['tenant']->id, $log->id), 'handle']);
        }

        $bodies = [];

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];

            if ($request->url() === 'https://services.leadconnectorhq.com/conversations/messages') {
                $bodies[] = (string) $request->data()['message'];
            }
        }

        $joined = implode("\n", $bodies);
        $this->assertStringContainsString('was cancelled', $joined);
        $this->assertStringContainsString('Payment $50.00 by offline', $joined);
        $this->assertStringContainsString('Booking fee $3.00', $joined);
        $this->assertStringContainsString('Tax $2.00', $joined);
        $this->assertStringContainsString('Total $55.00', $joined);
        $this->assertStringContainsString('is overdue', $joined);
        $this->assertStringContainsString('120 minutes before the start', $joined);
        $hook = NotificationHook::withoutGlobalScope(TenantScope::class)
            ->where('dedupe_key', 'booking.reminder:'.$reminderBooking->id.':120')
            ->firstOrFail();
        $this->assertSame('GoHighLevel sends this reminder.', $hook->delivery_error);
        $this->assertStringNotContainsString($token, $joined);
    }

    public function test_a_refund_itemizes_the_booking_fee_and_is_queued_by_the_refund(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $workspace = $this->workspace('dock.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $booking = $this->paidBooking($workspace['tenant']->id);
        $customer = Customer::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
        ]);
        $booking->customer_id = $customer->id;
        $booking->save();
        $this->link($workspace['tenant']->id, 'loc_alpha', $token);
        $this->actingAsWorkspace($workspace);
        Http::preventStrayRequests();
        Http::fake();

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$booking->id.'/refunds'), [
            'reason' => 'operator_cancelled',
            'idempotency_key' => 'refund-ghl',
        ])->assertCreated();

        Http::assertNothingSent();
        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('kind', 'refund.issued')->firstOrFail();
        $this->assertSame('queued', $log->status);
        $this->fakeGhl('loc_alpha', 'msg-refund');
        app()->call([new SendGhlBookingMessage($workspace['tenant']->id, $log->id), 'handle']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://services.leadconnectorhq.com/conversations/messages'
                && str_contains((string) $request->data()['message'], 'Booking fee refunded: $3.00')
                && str_contains((string) $request->data()['message'], 'Refunded to you: $103.00')
                && str_contains((string) $request->data()['message'], 'Service amount refunded: $100.00')
                && str_contains((string) $request->data()['message'], 'Tax refunded: $0.00');
        });
        $this->assertStringNotContainsString($token, (string) DB::table('ghl_message_logs')->get()->toJson());
    }

    private function link(string $tenantId, string $locationId, string $token): void
    {
        GhlConnection::factory()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'private_integration_token' => $token,
        ]);
    }

    private function fakeGhl(string $locationId, string $messageId): void
    {
        $this->replaceHttpFake([
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

    /**
     * @param  array<string, mixed>  $stubs
     */
    private function replaceHttpFake(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function booking(
        string $tenantId,
        string $name,
        ?string $email,
        ?string $phone,
        string $serviceName = 'Kayak rental',
    ): Booking {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => $serviceName,
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 2,
            'payload_hash' => hash('sha256', $tenantId.$serviceName.(string) $email.(string) $phone),
        ]);
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
            'name' => 'Sunset rental',
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
            'payload_hash' => hash('sha256', $tenantId.'paid'),
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
            'stripe_payment_intent_id' => 'pi_ghl',
        ]);
        PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => 300,
            'refunded_cents' => 0,
            'stripe_fee_id' => 'fee_pi_ghl',
            'channel' => 'direct',
        ]);
        StripeAccount::query()->create([
            'tenant_id' => $tenantId,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);

        return $booking;
    }

    /**
     * @return callable(): bool
     */
    private function listenFor(string $secret): callable
    {
        $leaked = false;
        Event::listen(MessageLogged::class, function (MessageLogged $event) use ($secret, &$leaked): void {
            $encoded = $event->message.' '.json_encode($event->context);

            if (str_contains($encoded, $secret)) {
                $leaked = true;
            }
        });

        return function () use (&$leaked): bool {
            return $leaked;
        };
    }
}
