<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Asset;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\HostAddressLookup;
use App\Services\Webhooks\WebhookPublisher;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesWorkspace;
use Tests\Support\MapHostAddressLookup;
use Tests\TestCase;

class WebhookDeliveryTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private MapHostAddressLookup $lookup;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lookup = new MapHostAddressLookup([
            'hooks.example.com' => ['1.1.1.1'],
            'private.example.com' => ['10.1.2.3'],
        ]);
        $this->app->instance(HostAddressLookup::class, $this->lookup);
        Http::preventStrayRequests();
    }

    public function test_private_and_loopback_urls_are_refused(): void
    {
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);

        foreach ([
            'http://hooks.example.com/hook',
            'https://127.0.0.1/hook',
            'https://10.0.0.8/hook',
            'https://[::1]/hook',
            'https://localhost/hook',
            'https://169.254.169.254/latest',
            'https://private.example.com/hook',
            'https://user:pass@hooks.example.com/hook',
        ] as $url) {
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints'), [
                'url' => $url,
                'events' => ['booking.created'],
            ])->assertUnprocessable()->assertJsonValidationErrors('url');
        }
    }

    public function test_the_secret_is_shown_once_and_staff_cannot_use_the_log(): void
    {
        $owner = $this->workspace('dock.test');
        $manager = $this->workspace('pier.test', 'manager');
        $staff = $this->workspace('slip.test', 'staff');
        $this->actingAsWorkspace($owner);

        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints'), [
            'name' => 'Ops',
            'url' => 'https://hooks.example.com/hook',
            'events' => ['booking.created', 'booking.cancelled'],
        ])->assertCreated();
        $secret = $created->json('data.secret');
        $id = $created->json('data.id');
        $this->assertIsString($secret);
        $this->assertStringStartsWith('whsec_', $secret);

        $shown = $this->getJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints/'.$id))->assertOk();
        $this->assertArrayNotHasKey('secret', $shown->json('data'));
        $listed = $this->getJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints'))->assertOk();
        $this->assertArrayNotHasKey('secret', $listed->json('data.0'));
        $this->assertStringNotContainsString($secret, $listed->getContent());

        $this->actingAsWorkspace($manager);
        $managerCreated = $this->postJson($this->tenantUrl('pier.test', '/api/v1/webhook-endpoints'), [
            'url' => 'https://hooks.example.com/manager',
            'events' => ['payment.succeeded'],
        ])->assertCreated();
        $this->assertIsString($managerCreated->json('data.secret'));
        $managerShown = $this->getJson($this->tenantUrl('pier.test', '/api/v1/webhook-endpoints/'.$managerCreated->json('data.id')))->assertOk();
        $this->assertArrayNotHasKey('secret', $managerShown->json('data'));

        $this->actingAsWorkspace($staff);
        $this->postJson($this->tenantUrl('slip.test', '/api/v1/webhook-endpoints'), [
            'url' => 'https://hooks.example.com/staff',
            'events' => ['booking.created'],
        ])->assertForbidden();
        $this->getJson($this->tenantUrl('slip.test', '/api/v1/webhook-endpoints'))->assertForbidden();
        $this->getJson($this->tenantUrl('slip.test', '/api/v1/webhook-deliveries'))->assertForbidden();
    }

    public function test_a_signed_delivery_is_logged_retried_and_can_be_redelivered(): void
    {
        Http::fake([
            'https://hooks.example.com/*' => Http::sequence()
                ->push('no', 500)
                ->push(str_repeat('x', 2500), 200)
                ->push('again', 200),
        ]);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints'), [
            'url' => 'https://hooks.example.com/hook',
            'events' => ['booking.created'],
        ])->assertCreated();
        $secret = $created->json('data.secret');
        $this->assertIsString($secret);

        app(TenantManager::class)->set($workspace['tenant']);
        app(WebhookPublisher::class)->publish(
            $workspace['tenant']->id,
            'booking.created',
            ['booking_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            'booking.created:once',
        );
        app(WebhookPublisher::class)->publish(
            $workspace['tenant']->id,
            'booking.created',
            ['booking_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'],
            'booking.created:once',
        );
        $this->assertSame('sync', config('queue.default'));
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertStringContainsString('DeliverWebhook', (string) DB::table('jobs')->value('payload'));

        app(TenantManager::class)->set($workspace['tenant']);
        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-deliveries/'.$delivery->id.'/redeliver'))
            ->assertStatus(409);

        $this->work();
        $available = (int) DB::table('jobs')->value('available_at');
        $this->assertGreaterThanOrEqual(time() + 25, $available);

        $this->releaseDelayedJobs();
        $this->work();

        app(TenantManager::class)->set($workspace['tenant']);
        $delivery->refresh();
        $this->assertSame('succeeded', $delivery->status);
        $this->assertSame(2, $delivery->logs()->count());
        $this->assertSame(2000, mb_strlen((string) $delivery->logs()->where('attempt', 2)->value('response_body')));
        $this->assertSame(0, DB::table('jobs')->count());

        Http::assertSent(function (Request $request) use ($secret): bool {
            $timestamp = $request->header('X-ReserveWorx-Timestamp')[0] ?? '';
            $signature = $request->header('X-ReserveWorx-Signature')[0] ?? '';

            return is_string($timestamp)
                && $signature === 'v1='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret)
                && $request->url() === 'https://hooks.example.com/hook';
        });

        $log = $this->getJson($this->tenantUrl('dock.test', '/api/v1/webhook-deliveries'))->assertOk();
        $this->assertSame(500, $log->json('data.0.log.0.response_code'));
        $this->assertSame(200, $log->json('data.0.log.1.response_code'));
        $this->assertIsInt($log->json('data.0.log.1.duration_ms'));

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-deliveries/'.$delivery->id.'/redeliver'))
            ->assertStatus(202);
        $this->work();
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('succeeded', $delivery->fresh()->status);
        $this->assertSame(3, WebhookDeliveryAttempt::query()->count());
    }

    public function test_retries_stop_after_five_attempts(): void
    {
        Http::fake([
            'https://hooks.example.com/*' => Http::response('no', 500),
        ]);
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints'), [
            'url' => 'https://hooks.example.com/hook',
            'events' => ['refund.created'],
        ])->assertCreated();

        app(TenantManager::class)->set($workspace['tenant']);
        app(WebhookPublisher::class)->publish($workspace['tenant']->id, 'refund.created', [
            'refund_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ], 'refund.created:once');

        for ($i = 0; $i < 5; $i++) {
            $this->releaseDelayedJobs();
            $this->work();
        }

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(5, WebhookDeliveryAttempt::query()->count());
        $this->assertSame('failed', WebhookDelivery::query()->value('status'));
        Http::assertSentCount(5);

        $this->work();
        Http::assertSentCount(5);
    }

    public function test_a_private_address_is_not_requested(): void
    {
        Http::fake();
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints'), [
            'url' => 'https://hooks.example.com/hook',
            'events' => ['booking.created'],
        ])->assertCreated();
        $this->lookup->set('hooks.example.com', ['127.0.0.1']);

        app(TenantManager::class)->set($workspace['tenant']);
        app(WebhookPublisher::class)->publish($workspace['tenant']->id, 'booking.created', [
            'booking_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ], 'booking.created:ssrf');
        $this->work();

        Http::assertNothingSent();
        app(TenantManager::class)->set($workspace['tenant']);
        $attempt = WebhookDeliveryAttempt::query()->firstOrFail();
        $this->assertSame('failed', $attempt->status);
        $this->assertNull($attempt->response_code);
        $this->assertSame('The endpoint address is not public.', $attempt->response_body);
    }

    public function test_booking_payment_and_refund_events_stay_inside_the_workspace(): void
    {
        Http::fake([
            'https://hooks.example.com/*' => Http::response('ok', 200),
        ]);
        [$workspace, $service] = $this->rental();
        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints'), [
            'url' => 'https://hooks.example.com/hook',
            'events' => ['booking.created', 'booking.cancelled', 'booking.changed', 'payment.succeeded', 'refund.created'],
        ])->assertCreated();

        app(TenantManager::class)->set($other['tenant']);
        WebhookEndpoint::query()->create([
            'tenant_id' => $other['tenant']->id,
            'url' => 'https://hooks.example.com/other',
            'secret' => 'whsec_other',
            'events' => ['booking.created', 'booking.cancelled', 'booking.changed', 'payment.succeeded', 'refund.created'],
            'active' => true,
        ]);

        $body = [
            'service_id' => $service->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'hook-1',
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ];
        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $body)->assertCreated();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), $body)->assertCreated();
        $bookingId = $created->json('data.id');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/cancel'))->assertOk();

        app(TenantManager::class)->set($workspace['tenant']);
        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $bookingId,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 1500,
        ]);
        Refund::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $bookingId,
            'reason' => 'customer_cancelled',
            'rules_version' => 'test',
            'customer_refund_cents' => 1500,
            'customer_fee_refund_cents' => 0,
            'application_fee_refund_cents' => 0,
            'refund_subtotal_cents' => 1500,
            'refund_tax_cents' => 0,
        ]);

        $events = WebhookDelivery::query()->orderBy('created_at')->orderBy('id')->pluck('event')->all();
        $this->assertSame([
            'booking.created',
            'booking.changed',
            'booking.cancelled',
            'payment.succeeded',
            'refund.created',
        ], $events);
        $this->assertSame(5, DB::table('jobs')->count());
        $this->assertSame(0, WebhookDelivery::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $other['tenant']->id)
            ->count());

        $foreign = WebhookDelivery::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $other['tenant']->id,
            'webhook_endpoint_id' => WebhookEndpoint::withoutGlobalScope(TenantScope::class)
                ->where('tenant_id', $other['tenant']->id)
                ->value('id'),
            'event' => 'booking.created',
            'payload' => ['booking_id' => 'foreign'],
            'status' => 'pending',
            'attempt_count' => 0,
        ]);
        (new DeliverWebhook($workspace['tenant']->id, $foreign->id))->handle(app(TenantManager::class));
        Http::assertNothingSent();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/webhook-deliveries/'.$foreign->id))->assertNotFound();

        app(TenantManager::class)->set($workspace['tenant']);
        try {
            app(WebhookPublisher::class)->publish($other['tenant']->id, 'booking.created', [], 'booking.created:foreign');
            $this->fail('A different workspace was queued.');
        } catch (TenantContextException $exception) {
            $this->assertSame('Refusing to queue a webhook for a different workspace.', $exception->getMessage());
        }
    }

    private function work(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--sleep' => 0,
            '--tries' => 5,
            '--max-time' => 15,
            // The suite is one process. The worker's 128 MB stop measures that
            // process, so a late test exits 12 before the delivery job runs.
            '--memory' => 1024,
        ])->assertSuccessful();
    }

    private function releaseDelayedJobs(): void
    {
        DB::table('jobs')->update([
            'available_at' => time() - 1,
            'reserved_at' => null,
        ]);
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
