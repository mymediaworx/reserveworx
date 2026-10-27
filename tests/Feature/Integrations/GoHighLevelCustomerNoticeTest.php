<?php

namespace Tests\Feature\Integrations;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Jobs\SendGhlBookingMessage;
use App\Jobs\SyncBookingToGoHighLevel;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Domain;
use App\Models\GhlConnection;
use App\Models\GhlMessageLog;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantNotificationSetting;
use App\Models\User;
use App\Notifications\BookingUpdateNotification;
use App\Services\Notifications\BookingEvents;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GoHighLevelCustomerNoticeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_failed_payment_is_sent_once_through_that_workspaces_location(): void
    {
        Bus::fake([SyncBookingToGoHighLevel::class]);
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaToken = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $booking = $this->heldBooking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', null, 'Kayak rental');
        $other = $this->heldBooking($beta['tenant']->id, 'Bea Other', 'bea@example.com', null, 'Other rental');
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        $money = $this->moneyCounts();

        app(TenantManager::class)->set($beta['tenant']);
        app(BookingEvents::class)->paymentFailed($beta['tenant']->id, $other->id, 'payment.failed:cs_beta');
        app(TenantManager::class)->forget();

        $this->assertSame(0, GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $alpha['tenant']->id)->count());

        $this->link($alpha['tenant']->id, 'loc_alpha', $alphaToken);
        app(TenantManager::class)->set($alpha['tenant']);
        app(BookingEvents::class)->paymentFailed($alpha['tenant']->id, $booking->id, 'payment.failed:cs_alpha', null);
        app(BookingEvents::class)->paymentFailed($alpha['tenant']->id, $booking->id, 'payment.failed:cs_alpha', null);

        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $alpha['tenant']->id)->sole();
        $this->assertSame('payment.failed', $log->kind);
        $this->assertSame('payment.failed:cs_alpha', $log->dedupe_key);
        $this->assertSame('queued', $log->status);
        $this->assertSame(1, DB::table('jobs')->where('payload', 'like', '%'.$log->id.'%')->count());
        $payload = (string) DB::table('jobs')->where('payload', 'like', '%'.$log->id.'%')->value('payload');
        $this->assertStringNotContainsString($alphaToken, $payload);
        $this->assertSame($money, $this->moneyCounts());

        $this->fakeGhl('loc_alpha', 'msg-failed');
        $job = new SendGhlBookingMessage($alpha['tenant']->id, $log->id);
        app()->call([$job, 'handle']);

        Http::assertSent(function (Request $request) use ($alphaToken, $booking): bool {
            $message = (string) ($request->data()['message'] ?? '');

            return $request->url() === 'https://services.leadconnectorhq.com/conversations/messages'
                && $request->hasHeader('Authorization', 'Bearer '.$alphaToken)
                && $request->data()['type'] === 'Email'
                && $request->data()['emailTo'] === 'ada@example.com'
                && $request->data()['subject'] === 'Payment failed: Kayak rental'
                && str_contains($message, 'did not go through')
                && str_contains($message, 'Jun 16, 2026 3:00 PM UTC')
                && ! str_contains($message, '$')
                && ! str_contains($message, $alphaToken)
                && str_contains($message, $booking->id);
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
        $this->assertSame('msg-failed', $log->ghl_message_id);
        $this->assertSame($money, $this->moneyCounts());
        $this->assertSame('unpaid', $booking->fresh()->payment_status->value);

        app(TenantManager::class)->forget();
        $this->artisan('integrations:dispatch')->assertSuccessful();
        Notification::assertNothingSent();
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('Sent through GoHighLevel.', NotificationHook::query()->where('kind', 'payment.failed')->sole()->delivery_error);
    }

    public function test_a_turned_off_failed_payment_is_skipped_and_an_unlinked_workspace_still_emails(): void
    {
        Bus::fake([SyncBookingToGoHighLevel::class]);
        config(['mail.default' => 'smtp']);
        Notification::fake();
        $linked = $this->workspace('alpha.test');
        $this->link($linked['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        $quiet = $this->heldBooking($linked['tenant']->id, 'Ada Lovelace', 'ada@example.com', null, 'Kayak rental');
        app(TenantManager::class)->set($linked['tenant']);
        $setting = TenantNotificationSetting::query()->firstOrFail();
        $setting->transactional_emails = ['payment.failed' => false];
        $setting->save();
        Http::preventStrayRequests();
        Http::fake();

        app(BookingEvents::class)->paymentFailed($linked['tenant']->id, $quiet->id, 'payment.failed:cs_quiet');

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertDatabaseHas('ghl_message_logs', [
            'booking_id' => $quiet->id,
            'kind' => 'payment.failed',
            'status' => 'skipped',
            'error' => 'The workspace turned this message off.',
        ]);

        $open = $this->workspace('open.test');
        $booking = $this->heldBooking($open['tenant']->id, 'Ada Lovelace', 'ada@example.com', null, 'Kayak rental');
        app(TenantManager::class)->set($open['tenant']);
        $before = $this->moneyCounts();
        app(BookingEvents::class)->paymentFailed($open['tenant']->id, $booking->id, 'payment.failed:cs_open');
        $this->assertSame(0, GhlMessageLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $open['tenant']->id)->count());
        $this->assertSame($before, $this->moneyCounts());
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 1);
        Notification::assertSentOnDemand(BookingUpdateNotification::class, function (BookingUpdateNotification $mail): bool {
            $body = implode("\n", $mail->lines);

            return str_contains($mail->subject, 'did not go through')
                && str_contains($body, 'did not go through')
                && ! str_contains($body, '$');
        });
    }

    public function test_a_guest_move_is_sent_through_gohighlevel_and_does_not_change_the_price(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        Bus::fake([SyncBookingToGoHighLevel::class]);
        config(['mail.default' => 'smtp']);
        Notification::fake();
        [$workspace, $token] = $this->bookable();
        $beta = $this->workspace('beta.test');
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        $bookingId = $this->hold($token, '15:00', 'guest-move');
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();
        $alphaToken = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $this->link($workspace['tenant']->id, 'loc_alpha', $alphaToken);
        $money = $this->moneyCounts();

        $found = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/lookup'), [
            'reference' => $bookingId,
            'email' => 'ada@example.com',
        ])->assertRedirect();
        $manage = $this->get((string) $found->headers->get('Location'))->assertOk();
        preg_match('/action="([^"]*reschedule[^"]*)"/', (string) $manage->getContent(), $action);

        $this->post(html_entity_decode($action[1]), [
            'date' => '2026-06-15',
            'start' => '17:00',
        ])->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        $log = GhlMessageLog::query()->where('kind', 'booking.guest_rescheduled')->sole();
        $this->assertSame('booking.guest_rescheduled:'.$bookingId.':202606151700', $log->dedupe_key);
        $this->assertSame('queued', $log->status);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringNotContainsString($alphaToken, (string) DB::table('jobs')->value('payload'));
        $this->assertSame($money, $this->moneyCounts());

        $this->post(html_entity_decode($action[1]), [
            'date' => '2026-06-15',
            'start' => '17:00',
        ])->assertOk();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, GhlMessageLog::query()->where('kind', 'booking.guest_rescheduled')->count());
        $this->assertSame(1, DB::table('jobs')->count());

        $this->fakeGhl('loc_alpha', 'msg-moved');
        app()->call([(new SendGhlBookingMessage($workspace['tenant']->id, $log->id)), 'handle']);

        Http::assertSent(function (Request $request) use ($alphaToken): bool {
            $message = (string) ($request->data()['message'] ?? '');

            return $request->url() === 'https://services.leadconnectorhq.com/conversations/messages'
                && $request->hasHeader('Authorization', 'Bearer '.$alphaToken)
                && $request->data()['subject'] === 'New time: Hourly boat'
                && str_contains($message, 'now on Jun 15, 2026 5:00 PM UTC')
                && ! str_contains($message, '$')
                && ! str_contains($message, $alphaToken);
        });
        Http::assertNotSent(function (Request $request) use ($betaToken): bool {
            return str_contains($request->url().' '.$request->body(), $betaToken)
                || str_contains($request->body(), 'loc_beta');
        });

        $log->refresh();
        $this->assertSame('succeeded', $log->status);
        $this->assertSame($money, $this->moneyCounts());
        app(TenantManager::class)->forget();
        Notification::fake();

        $this->artisan('integrations:dispatch')->assertSuccessful();
        Notification::assertNothingSent();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('Sent through GoHighLevel.', NotificationHook::query()->where('kind', 'booking.guest_rescheduled')->sole()->delivery_error);
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
        ];
    }

    private function link(string $tenantId, string $locationId, string $token): void
    {
        GhlConnection::factory()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'private_integration_token' => $token,
        ]);
    }

    private function heldBooking(string $tenantId, string $name, string $email, ?string $phone, string $serviceName): Booking
    {
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
            'status' => BookingStatus::Held,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 1,
            'payload_hash' => hash('sha256', $tenantId.$serviceName.$email),
        ]);
    }

    /**
     * @param  array<string, mixed>  $stubs
     */
    private function fakeGhl(string $locationId, string $messageId): void
    {
        Http::swap(new Factory);
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

    private function hold(string $token, string $start, string $key): string
    {
        $response = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$this->serviceId), [
            'date' => '2026-06-15',
            'start' => $start,
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ])->assertRedirect();
        preg_match('#/checkout/([^?]+)#', (string) $response->headers->get('Location'), $matches);

        return $matches[1];
    }

    private string $serviceId = '';

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: string}
     */
    private function bookable(): array
    {
        $workspace = $this->workspace('dock.test');
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
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();
        $token = $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
        $this->serviceId = $service->id;

        return [$workspace, $token];
    }
}
