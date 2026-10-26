<?php

namespace Tests\Feature\Integrations;

use App\Enums\TenantStatus;
use App\Models\GhlMessageLog;
use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Integrations\GhlSyncLogger;
use App\Services\Integrations\Outbox;
use App\Services\Platform\TenantExporter;
use App\Services\Webhooks\HostAddressLookup;
use App\Services\Webhooks\WebhookAttemptFailed;
use App\Services\Webhooks\WebhookSender;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\CreatesWorkspace;
use Tests\Support\MapHostAddressLookup;
use Tests\TestCase;

class IntegrationLogTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_each_outbox_retry_is_kept_for_that_workspace_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $tenants = app(TenantManager::class);
        $outbox = app(Outbox::class);
        $bookingId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

        $tenants->set($alpha['tenant']);
        $outbox->enqueue($alpha['tenant']->id, 'booking.confirmed', 'booking.confirmed:1', [
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $bookingId,
        ]);
        $outbox->enqueue($alpha['tenant']->id, 'booking.confirmed', 'booking.confirmed:1', [
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $bookingId,
        ]);
        $this->assertSame(1, IntegrationOutbox::query()->count());
        $this->assertSame(0, IntegrationLog::query()->count());

        $tenants->set($beta['tenant']);
        $outbox->enqueue($beta['tenant']->id, 'booking.cancelled', 'booking.cancelled:1', [
            'tenant_id' => $beta['tenant']->id,
            'booking_id' => $bookingId,
        ]);
        $beta['tenant']->status = TenantStatus::Suspended;
        $beta['tenant']->save();
        $tenants->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();

        foreach (['2026-06-16 12:01:00', '2026-06-16 12:06:00', '2026-06-16 12:21:00', '2026-06-16 13:21:00'] as $moment) {
            Carbon::setTestNow(Carbon::parse($moment, 'UTC'));
            $this->artisan('integrations:dispatch')->assertSuccessful();
        }

        $tenants->set($alpha['tenant']);
        $logs = IntegrationLog::query()->orderBy('attempt')->get();
        $this->assertSame(['deferred', 'deferred', 'deferred', 'deferred', 'held'], $logs->pluck('status')->all());
        $this->assertSame([1, 2, 3, 4, 5], $logs->pluck('attempt')->all());
        $this->assertSame('outbox', $logs->first()?->channel);
        $this->assertSame(
            [
                '2026-06-16T12:01:00+00:00',
                '2026-06-16T12:06:00+00:00',
                '2026-06-16T12:21:00+00:00',
                '2026-06-16T13:21:00+00:00',
                '2026-06-16T16:21:00+00:00',
            ],
            $logs->map(fn (IntegrationLog $log): ?string => $log->available_at?->utc()->toIso8601String())->all(),
        );
        $this->assertSame('GoHighLevel is not connected. The event stays queued.', $logs->first()?->error);
        $this->assertStringNotContainsString($bookingId, $logs->toJson());

        $tenants->set($beta['tenant']);
        $this->assertSame(0, IntegrationLog::query()->count());
        $untouched = IntegrationOutbox::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->firstOrFail();
        $this->assertSame(0, $untouched->attempts);
        $tenants->forget();

        $this->actingAsWorkspace($alpha);
        $response = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/integrations/outbox'))
            ->assertOk()
            ->assertJsonCount(5, 'data.logs')
            ->assertJsonPath('data.logs.0.channel', 'outbox')
            ->assertJsonPath('data.logs.0.kind', 'booking.confirmed')
            ->assertJsonPath('data.logs.0.attempt', 5)
            ->assertJsonPath('data.logs.0.status', 'held');
        $this->assertStringNotContainsString($bookingId, $response->getContent());
        $this->assertStringNotContainsString('booking.cancelled', $response->getContent());

        $this->actingAs($alpha['user']);
        $this->get($this->tenantUrl('alpha.test', '/admin/integrations'))
            ->assertOk()
            ->assertSee('Attempts')
            ->assertSee('Outbox')
            ->assertSee('deferred')
            ->assertSee('held')
            ->assertSee('booking.confirmed')
            ->assertDontSee($bookingId)
            ->assertDontSee('booking.cancelled');

        $this->actingAsWorkspace($beta);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/integrations/outbox'))->assertForbidden();

        $this->actingAs($beta['user']);
        $this->get($this->tenantUrl('alpha.test', '/admin/integrations'))->assertForbidden();
    }

    public function test_the_diagnostics_list_keeps_the_latest_twenty_attempts(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);

        for ($attempt = 1; $attempt <= 21; $attempt++) {
            IntegrationLog::factory()->create([
                'tenant_id' => $alpha['tenant']->id,
                'attempt' => $attempt,
                'kind' => 'booking.confirmed',
            ]);
        }

        $this->actingAsWorkspace($alpha);
        $response = $this->getJson($this->tenantUrl('alpha.test', '/api/v1/integrations/outbox'))
            ->assertOk()
            ->assertJsonCount(20, 'data.logs')
            ->assertJsonPath('data.logs.0.attempt', 21);

        $attempts = collect($response->json('data.logs'))->pluck('attempt')->all();
        $this->assertNotContains(1, $attempts);
        $this->assertContains(21, $attempts);
    }

    public function test_a_webhook_attempt_is_logged_without_the_response_or_the_secret(): void
    {
        $this->app->instance(HostAddressLookup::class, new MapHostAddressLookup([
            'hooks.example.com' => ['1.1.1.1'],
        ]));
        Http::preventStrayRequests();

        $alpha = $this->workspace('dock.test');
        $this->actingAsWorkspace($alpha);
        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/webhook-endpoints'), [
            'url' => 'https://hooks.example.com/hook',
            'events' => ['booking.created'],
        ])->assertCreated();
        $secret = $created->json('data.secret');
        $this->assertIsString($secret);

        $tenants = app(TenantManager::class);
        $tenants->set($alpha['tenant']);
        $endpoint = WebhookEndpoint::query()->firstOrFail();
        $bookingId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $delivery = WebhookDelivery::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'booking.created',
            'dedupe_key' => 'booking.created:log',
            'payload' => ['booking_id' => $bookingId, 'secret' => $secret],
            'status' => 'pending',
            'attempt_count' => 0,
        ]);

        Http::fake([
            'https://hooks.example.com/*' => Http::sequence()
                ->push('leaked '.$secret.' '.$bookingId, 500)
                ->push('ok '.$secret, 200),
        ]);

        try {
            app(WebhookSender::class)->send($delivery);
            $this->fail('A failed delivery was treated as sent.');
        } catch (WebhookAttemptFailed) {
            $failed = IntegrationLog::query()->firstOrFail();
            $this->assertSame('webhook', $failed->channel);
            $this->assertSame('booking.created', $failed->kind);
            $this->assertSame(1, $failed->attempt);
            $this->assertSame('failed', $failed->status);
            $this->assertSame(500, $failed->http_status);
            $this->assertSame('The endpoint refused the delivery.', $failed->error);
        }

        app(WebhookSender::class)->send($delivery->fresh() ?? $delivery);
        $succeeded = IntegrationLog::query()->orderByDesc('attempt')->firstOrFail();
        $this->assertSame('succeeded', $succeeded->status);
        $this->assertSame(200, $succeeded->http_status);
        $this->assertNull($succeeded->error);
        $this->assertSame(2, IntegrationLog::query()->count());

        $endpoint->active = false;
        $endpoint->save();
        $inactive = WebhookDelivery::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'booking.cancelled',
            'dedupe_key' => 'booking.cancelled:inactive',
            'payload' => ['booking_id' => $bookingId],
            'status' => 'pending',
            'attempt_count' => 0,
        ]);
        app(WebhookSender::class)->send($inactive);
        $closed = IntegrationLog::query()->where('kind', 'booking.cancelled')->firstOrFail();
        $this->assertSame('failed', $closed->status);
        $this->assertSame('The endpoint is not active.', $closed->error);

        $stored = (string) IntegrationLog::withoutGlobalScope(TenantScope::class)->get()->toJson();
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertStringNotContainsString($bookingId, $stored);
    }

    public function test_ghl_attempts_are_logged_without_the_token_and_cannot_be_rewritten(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $tenants = app(TenantManager::class);
        $secret = 'pit-live-token-value';
        $tenants->set($alpha['tenant']);

        app(GhlSyncLogger::class)->write(
            $alpha['tenant']->id,
            'booking.confirmed',
            2,
            'failed',
            'loc_alpha',
            null,
            null,
            401,
            'Bearer '.$secret.' refused',
            $secret,
        );

        $sync = IntegrationLog::query()->where('channel', 'ghl_sync')->firstOrFail();
        $this->assertSame('booking.confirmed', $sync->kind);
        $this->assertSame(2, $sync->attempt);
        $this->assertSame('failed', $sync->status);
        $this->assertSame(401, $sync->http_status);
        $this->assertStringNotContainsString($secret, (string) $sync->error);
        $this->assertStringContainsString('[redacted]', (string) $sync->error);

        $message = GhlMessageLog::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'kind' => 'booking.reminder',
            'dedupe_key' => 'booking.reminder:1',
            'status' => 'queued',
            'attempts' => 0,
            'error' => 'Bearer '.$secret,
        ]);
        $this->assertSame(0, IntegrationLog::query()->where('channel', 'ghl_message')->count());

        $message->status = 'failed';
        $message->attempts = 1;
        $message->http_status = 429;
        $message->error = 'Bearer '.$secret.' limited';
        $message->save();

        $messageLog = IntegrationLog::query()->where('channel', 'ghl_message')->firstOrFail();
        $this->assertSame('booking.reminder', $messageLog->kind);
        $this->assertSame(1, $messageLog->attempt);
        $this->assertSame(429, $messageLog->http_status);
        $this->assertSame('Bearer [redacted] limited', $messageLog->error);

        $message->attempts = 2;
        $message->status = 'succeeded';
        $message->error = null;
        $message->save();
        $this->assertSame(2, IntegrationLog::query()->where('channel', 'ghl_message')->count());
        $second = IntegrationLog::query()->where('channel', 'ghl_message')->orderByDesc('attempt')->firstOrFail();
        $this->assertSame('succeeded', $second->status);
        $this->assertNull($second->error);

        app(GhlSyncLogger::class)->write(
            $beta['tenant']->id,
            'connection.test',
            1,
            'skipped',
            'loc_beta',
            null,
            null,
            null,
            'beta-only-marker',
            null,
        );
        $this->assertNull(IntegrationLog::query()->where('kind', 'connection.test')->first());

        Storage::fake('local');
        $export = app(TenantExporter::class)->create($alpha['tenant'], $alpha['user']);
        $json = Storage::disk('local')->get($export->path);
        $this->assertIsString($json);
        $this->assertSame(3, $export->row_counts['integration_logs'] ?? null);
        $this->assertStringContainsString('ghl_sync', $json);
        $this->assertStringContainsString('booking.reminder', $json);
        $this->assertStringNotContainsString($secret, $json);
        $this->assertStringNotContainsString('beta-only-marker', $json);

        $sync->error = 'changed';

        try {
            $sync->save();
            $this->fail('A log was changed.');
        } catch (LogicException $exception) {
            $this->assertSame('Integration logs are a record of an attempt and cannot be changed.', $exception->getMessage());
        }

        $this->assertNotSame('changed', $sync->fresh()?->error);

        try {
            $sync->delete();
            $this->fail('A log was deleted.');
        } catch (LogicException $exception) {
            $this->assertSame('Integration logs are a record of an attempt and cannot be deleted.', $exception->getMessage());
        }

        $this->assertNotNull($sync->fresh());

        $tenants->forget();

        try {
            IntegrationLog::query()->create([
                'channel' => 'outbox',
                'kind' => 'booking.confirmed',
                'attempt' => 1,
                'status' => 'deferred',
            ]);
            $this->fail('A log was stored without a workspace.');
        } catch (MissingTenantContextException $exception) {
            $this->assertStringContainsString('Tenant context is required', $exception->getMessage());
        }
    }
}
