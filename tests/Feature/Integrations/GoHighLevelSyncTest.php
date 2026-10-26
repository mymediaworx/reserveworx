<?php

namespace Tests\Feature\Integrations;

use App\Domain\Payments\StripeGateway;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Jobs\SyncBookingToGoHighLevel;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GhlConnection;
use App\Models\GhlMapping;
use App\Models\GhlSyncLog;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PricingSnapshot;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Integrations\GhlRequestFailed;
use App\Services\Notifications\BookingEvents;
use App\Services\Payments\FakeStripeGateway;
use App\Support\BookingReference;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GoHighLevelSyncTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_platform_administrator_can_link_a_sub_account_without_exposing_the_token(): void
    {
        $admin = $this->platformAdmin();
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $leaks = $this->listenFor($token);

        $denied = $this->putJson($this->platform($workspace['tenant']->id), [
            'location_id' => 'loc_alpha',
            'private_integration_token' => 'pit-too-short',
        ]);
        $denied->assertUnprocessable();
        $this->assertStringNotContainsString('pit-too-short', (string) $denied->getContent());

        $linked = $this->putJson($this->platform($workspace['tenant']->id), [
            'location_id' => 'loc_alpha',
            'private_integration_token' => $token,
        ])->assertOk();

        $linked->assertJsonPath('data.linked', true)
            ->assertJsonPath('data.location_id', 'loc_alpha');
        $this->assertStringNotContainsString($token, (string) $linked->getContent());

        $shown = $this->getJson($this->platform($workspace['tenant']->id))->assertOk();
        $shown->assertJsonPath('data.location_id', 'loc_alpha');
        $this->assertStringNotContainsString($token, (string) $shown->getContent());

        $connection = GhlConnection::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame($token, $connection->private_integration_token);
        $this->assertArrayNotHasKey('private_integration_token', $connection->toArray());
        $this->assertStringNotContainsString($token, (string) DB::table('ghl_connections')->get()->toJson());

        $audit = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.ghl_linked')
            ->firstOrFail();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertStringNotContainsString($token, (string) json_encode($audit->properties));
        $this->assertStringNotContainsString($token, serialize(new SyncBookingToGoHighLevel(
            $workspace['tenant']->id,
            '01J00000000000000000000000',
            'booking.created',
        )));

        $this->deleteJson($this->platform($workspace['tenant']->id))
            ->assertOk()
            ->assertJsonPath('data.linked', false);
        $this->assertDatabaseMissing('ghl_connections', ['tenant_id' => $workspace['tenant']->id]);
        $this->assertFalse($leaks());
    }

    public function test_tenant_staff_cannot_link_or_test_a_sub_account(): void
    {
        $workspace = $this->workspace('alpha.test', 'staff');

        $this->getJson($this->platform($workspace['tenant']->id))->assertUnauthorized();

        $this->actingAsWorkspace($workspace);
        $body = [
            'location_id' => 'loc_alpha',
            'private_integration_token' => 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04',
        ];

        $this->putJson($this->platform($workspace['tenant']->id), $body)->assertForbidden();
        $this->getJson($this->platform($workspace['tenant']->id))->assertForbidden();
        $this->postJson($this->platform($workspace['tenant']->id).'/test')->assertForbidden();
        $this->deleteJson($this->platform($workspace['tenant']->id))->assertForbidden();
        $this->getJson($this->platform($workspace['tenant']->id).'/logs')->assertForbidden();
    }

    public function test_connection_test_reports_success_and_refusal_without_the_token(): void
    {
        $this->platformAdmin();
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $leaks = $this->listenFor($token);

        $this->postJson($this->platform($workspace['tenant']->id).'/test')
            ->assertStatus(422)
            ->assertJsonPath('message', 'GoHighLevel is not linked.');

        $this->link($workspace['tenant']->id, 'loc_alpha', $token);

        $status = 200;
        Http::preventStrayRequests();
        Http::fake(function () use (&$status, $token) {
            if ($status === 401) {
                return Http::response(['message' => $token], 401);
            }

            return Http::response([
                'location' => ['id' => 'loc_alpha'],
            ], 200);
        });

        $ok = $this->postJson($this->platform($workspace['tenant']->id).'/test')->assertOk();
        $ok->assertJsonPath('data.ok', true)->assertJsonPath('data.location_id', 'loc_alpha');
        $this->assertStringNotContainsString($token, (string) $ok->getContent());
        Http::assertSent(function (Request $request) use ($token): bool {
            return $request->url() === 'https://services.leadconnectorhq.com/locations/loc_alpha'
                && $request->hasHeader('Authorization', 'Bearer '.$token)
                && $request->hasHeader('Version', '2021-07-28');
        });

        $status = 401;

        $refused = $this->postJson($this->platform($workspace['tenant']->id).'/test')->assertStatus(422);
        $this->assertSame('GoHighLevel refused the connection.', $refused->json('message'));
        $this->assertStringNotContainsString($token, (string) $refused->getContent());

        $logs = $this->getJson($this->platform($workspace['tenant']->id).'/logs')->assertOk();
        $this->assertStringNotContainsString($token, (string) $logs->getContent());
        $this->assertSame('failed', $logs->json('data.0.status'));
        $this->assertSame(401, $logs->json('data.0.http_status'));
        $this->assertStringNotContainsString($token, (string) DB::table('ghl_sync_logs')->get()->toJson());
        $this->assertFalse($leaks());
    }

    public function test_sync_is_disabled_until_a_workspace_is_linked(): void
    {
        $workspace = $this->workspace('alpha.test');
        Http::preventStrayRequests();
        Http::fake();

        $booking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');
        $booking->participants = 3;
        $booking->save();
        $booking->status = BookingStatus::Cancelled;
        $booking->save();
        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 5000,
            'fee_cents' => 0,
        ]);

        Http::assertNothingSent();
        $this->assertSame(0, GhlSyncLog::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_linked_workspace_upserts_the_contact_and_records_each_event(): void
    {
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $leaks = $this->listenFor($token);
        $this->link($workspace['tenant']->id, 'loc_alpha', $token);
        $this->fakeContactApi('loc_alpha');

        $booking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Sunset rental');
        $booking->participants = 3;
        $booking->save();
        $payment = Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 5000,
            'fee_cents' => 0,
        ]);
        $booking->status = BookingStatus::Cancelled;
        $booking->save();

        $upserts = [];
        $updates = [];
        $tags = [];
        $notes = [];

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer '.$token));
            $this->assertTrue($request->hasHeader('Version', '2021-07-28'));
            $this->assertStringStartsWith('https://services.leadconnectorhq.com/', $request->url());

            if (str_ends_with($request->url(), '/contacts/upsert')) {
                $upserts[] = $request->data();
            }

            if ($request->method() === 'PUT' && str_contains($request->url(), '/contacts/')) {
                $updates[] = $request->data();
                $this->assertStringEndsWith('/contacts/contact-1', $request->url());
                $this->assertArrayNotHasKey('locationId', $request->data());
                $this->assertArrayNotHasKey('tags', $request->data());
            }

            if (str_contains($request->url(), '/tags')) {
                $tags[] = $request->data();
            }

            if (str_contains($request->url(), '/notes')) {
                $notes[] = $request->data();
            }
        }

        $this->assertCount(1, $upserts);
        $this->assertCount(3, $updates);
        $this->assertCount(4, $tags);
        $this->assertCount(4, $notes);
        $this->assertDatabaseHas('ghl_mappings', [
            'tenant_id' => $workspace['tenant']->id,
            'customer_id' => $booking->customer_id,
            'location_id' => 'loc_alpha',
            'contact_id' => 'contact-1',
        ]);

        foreach ($upserts as $upsert) {
            $this->assertSame('loc_alpha', $upsert['locationId']);
            $this->assertSame('ada@example.com', $upsert['email']);
            $this->assertSame('312-555-0100', $upsert['phone']);
            $this->assertSame('Ada', $upsert['firstName']);
            $this->assertSame('Lovelace', $upsert['lastName']);
            $this->assertSame('ReserveWorx', $upsert['source']);
            $this->assertArrayNotHasKey('tags', $upsert);
        }

        $sentTags = array_map(fn (array $payload): array => $payload['tags'], $tags);
        $this->assertContains(['reserveworx', 'reserveworx-booking-created'], $sentTags);
        $this->assertContains(['reserveworx', 'reserveworx-booking-changed'], $sentTags);
        $this->assertContains(['reserveworx', 'reserveworx-booking-cancelled'], $sentTags);
        $this->assertContains(['reserveworx', 'reserveworx-payment-succeeded'], $sentTags);

        $bodies = array_map(fn (array $payload): string => (string) $payload['body'], $notes);
        $joined = implode("\n", $bodies);
        $this->assertStringContainsString('Service Sunset rental', $joined);
        $this->assertStringContainsString('Reference '.BookingReference::short($booking->id), $joined);
        $this->assertStringContainsString('Attribution unrecorded', $joined);
        $this->assertStringContainsString('Consent feedback allowed', $joined);
        $this->assertStringContainsString('Starts 2026-06-16 15:00:00 UTC', $joined);
        $this->assertStringContainsString('reserveworx:booking.created:'.$booking->id.':', $joined);
        $this->assertStringContainsString('reserveworx:payment.succeeded:'.$booking->id.':'.$payment->id, $joined);

        foreach ($notes as $note) {
            $this->assertArrayNotHasKey('userId', $note);
        }

        $this->assertSame(4, GhlSyncLog::withoutGlobalScope(TenantScope::class)->where('status', 'succeeded')->count());
        $this->assertStringNotContainsString($token, (string) DB::table('ghl_sync_logs')->get()->toJson());
        $this->assertFalse($leaks());
    }

    public function test_only_booking_details_and_a_paid_payment_are_queued(): void
    {
        Queue::fake();
        $workspace = $this->workspace('alpha.test');
        $this->link($workspace['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');

        $booking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');
        $booking->attention_reason = 'maintenance';
        $booking->save();
        $booking->starts_at = '2026-06-16 17:00:00';
        $booking->save();
        $booking->forceFill([
            'participants' => 4,
            'status' => BookingStatus::Cancelled,
        ])->save();

        $payment = Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'pending',
            'amount_cents' => 5000,
            'fee_cents' => 0,
        ]);
        $payment->status = 'paid';
        $payment->save();
        $payment->amount_cents = 5100;
        $payment->save();

        Queue::assertPushed(SyncBookingToGoHighLevel::class, 4);
        Queue::assertPushed(SyncBookingToGoHighLevel::class, fn (SyncBookingToGoHighLevel $job): bool => $job->kind === 'booking.created' && $job->tenantId === $workspace['tenant']->id);
        Queue::assertPushed(SyncBookingToGoHighLevel::class, fn (SyncBookingToGoHighLevel $job): bool => $job->kind === 'booking.changed');
        Queue::assertPushed(SyncBookingToGoHighLevel::class, fn (SyncBookingToGoHighLevel $job): bool => $job->kind === 'booking.cancelled');
        Queue::assertPushed(SyncBookingToGoHighLevel::class, fn (SyncBookingToGoHighLevel $job): bool => $job->kind === 'payment.succeeded' && $job->paymentId === $payment->id);
    }

    public function test_a_workspace_never_sends_another_workspaces_contact(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaToken = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $betaBooking = $this->booking($beta['tenant']->id, 'Bea Other', 'bea@example.com', '312-555-0199');
        $this->link($alpha['tenant']->id, 'loc_alpha', $alphaToken);
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        $this->fakeContactApi('loc_alpha');

        $this->booking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');

        Http::assertSent(function (Request $request) use ($alphaToken): bool {
            return str_contains($request->url(), '/contacts/upsert')
                && $request->hasHeader('Authorization', 'Bearer '.$alphaToken)
                && $request->data()['locationId'] === 'loc_alpha'
                && $request->data()['email'] === 'ada@example.com';
        });
        Http::assertNotSent(function (Request $request) use ($betaToken): bool {
            $authorization = implode(' ', (array) $request->header('Authorization'));
            $raw = $request->url().' '.$authorization.' '.$request->body();

            return str_contains($raw, $betaToken)
                || str_contains($raw, 'loc_beta')
                || str_contains($raw, 'bea@example.com');
        });

        Http::fake();
        Http::preventStrayRequests();
        GhlSyncLog::withoutGlobalScope(TenantScope::class)->delete();

        app()->call([new SyncBookingToGoHighLevel($alpha['tenant']->id, $betaBooking->id, 'booking.created'), 'handle']);

        Http::assertNothingSent();
        $log = GhlSyncLog::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame($alpha['tenant']->id, $log->tenant_id);
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->booking_id);
        $this->assertStringNotContainsString('bea@example.com', (string) $log->error);
        $this->assertStringNotContainsString($betaToken, (string) DB::table('ghl_sync_logs')->get()->toJson());
    }

    public function test_a_failed_attempt_is_logged_and_retries_are_declared(): void
    {
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $leaks = $this->listenFor($token);
        $this->link($workspace['tenant']->id, 'loc_alpha', $token);
        Http::preventStrayRequests();
        Http::fake([
            'https://services.leadconnectorhq.com/*' => Http::response(['message' => $token], 401),
        ]);

        $job = new SyncBookingToGoHighLevel('tenant', 'booking', 'booking.created');
        $this->assertSame(5, $job->tries);
        $this->assertSame([60, 300, 900, 3600, 10800], $job->backoff());

        try {
            $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');
            $this->fail('The GoHighLevel failure was not raised for the queue to retry.');
        } catch (GhlRequestFailed $exception) {
            $this->assertSame('GoHighLevel upsert returned HTTP 401.', $exception->getMessage());
            $this->assertStringNotContainsString($token, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $this->assertDatabaseHas('bookings', ['tenant_id' => $workspace['tenant']->id]);
        $this->assertDatabaseHas('ghl_sync_logs', [
            'tenant_id' => $workspace['tenant']->id,
            'kind' => 'booking.created',
            'status' => 'failed',
            'http_status' => 401,
            'attempt' => 1,
        ]);
        $this->assertStringNotContainsString($token, (string) DB::table('ghl_sync_logs')->get()->toJson());
        $this->assertFalse($leaks());
    }

    public function test_a_rate_limit_waits_for_retry_after_and_does_not_use_a_failure_try(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaToken = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $leaks = $this->listenFor($alphaToken);
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        $this->fakeContactApi('loc_beta');
        $betaBooking = $this->booking($beta['tenant']->id, 'Bea Other', 'bea@example.com', '312-555-0199');
        $this->assertSame('confirmed', $betaBooking->status->value);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'https://services.leadconnectorhq.com/*' => Http::response(['message' => $alphaToken], 429, [
                'Retry-After' => '120',
            ]),
        ]);
        $this->link($alpha['tenant']->id, 'loc_alpha', $alphaToken);

        $booking = $this->booking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');

        $log = GhlSyncLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertSame(429, $log->http_status);
        $this->assertSame(1, $log->attempt);
        $this->assertStringContainsString('Waiting 120 seconds', (string) $log->error);
        $this->assertStringNotContainsString($alphaToken, (string) $log->error);
        $this->assertSame(1, DB::table('jobs')->count());
        $payload = (string) DB::table('jobs')->value('payload');
        $this->assertStringContainsString('SyncBookingToGoHighLevel', $payload);
        $this->assertStringContainsString($alpha['tenant']->id, $payload);
        $this->assertStringContainsString($booking->id, $payload);
        $this->assertStringNotContainsString($betaToken, $payload);
        $this->assertStringNotContainsString($alphaToken, $payload);
        $this->assertStringNotContainsString('loc_beta', $payload);
        $this->assertEqualsWithDelta(now()->addSeconds(120)->getTimestamp(), (int) DB::table('jobs')->value('available_at'), 5);
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('refunds')->count());
        $this->assertSame(0, DB::table('platform_fees')->count());
        $this->assertFalse($leaks());
    }

    public function test_a_rate_limit_without_retry_after_uses_the_backoff_schedule(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->link($workspace['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        Http::preventStrayRequests();
        Http::fake([
            'https://services.leadconnectorhq.com/*' => Http::response([], 429, [
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Interval-Milliseconds' => '10000',
            ]),
        ]);

        $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');

        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertEqualsWithDelta(now()->addSeconds(10)->getTimestamp(), (int) DB::table('jobs')->value('available_at'), 5);
        $log = GhlSyncLog::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame(1, $log->attempt);
        $this->assertStringContainsString('Waiting 10 seconds', (string) $log->error);
    }

    public function test_a_rate_limit_that_outlasts_the_automatic_waits_is_ready_to_send_again(): void
    {
        $workspace = $this->workspace('alpha.test');
        $booking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');
        $this->link($workspace['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        Http::preventStrayRequests();
        Http::fake([
            'https://services.leadconnectorhq.com/*' => Http::response([], 429, [
                'Retry-After' => '30',
            ]),
        ]);
        $job = new SyncBookingToGoHighLevel($workspace['tenant']->id, $booking->id, 'booking.created');
        $job->rateLimitWaits = 8;

        app()->call([$job, 'handle']);

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertDatabaseHas('ghl_sync_logs', [
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $booking->id,
            'attempt' => 5,
            'status' => 'failed',
            'http_status' => 429,
            'error' => 'GoHighLevel rate limit lasted through the automatic waits.',
        ]);
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('refunds')->count());
        $this->assertSame(0, DB::table('platform_fees')->count());
    }

    public function test_a_customer_without_email_or_phone_is_skipped(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->link($workspace['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        Http::preventStrayRequests();
        Http::fake();

        $customer = Customer::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'email' => null,
            'phone' => null,
        ]);
        $this->booking($workspace['tenant']->id, 'Ada Lovelace', null, null, 'No contact', $customer);

        Http::assertNothingSent();
        $this->assertDatabaseHas('ghl_sync_logs', [
            'tenant_id' => $workspace['tenant']->id,
            'status' => 'skipped',
        ]);
    }

    public function test_the_version_header_follows_configuration(): void
    {
        config(['services.ghl.version' => 'v3']);
        $workspace = $this->workspace('alpha.test');
        $this->link($workspace['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        $this->fakeContactApi('loc_alpha');

        $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100');

        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('Version', 'v3');
        });
    }

    public function test_the_workspace_export_omits_the_token(): void
    {
        Storage::fake('local');
        $this->platformAdmin();
        $workspace = $this->workspace('alpha.test');
        $token = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $this->link($workspace['tenant']->id, 'loc_alpha', $token);

        $created = $this->postJson('http://localhost/api/v1/platform/tenants/'.$workspace['tenant']->id.'/exports')
            ->assertCreated();
        $path = 'tenant-exports/'.$workspace['tenant']->id.'/'.$created->json('data.id').'.json';
        $json = Storage::disk('local')->get($path);

        $this->assertIsString($json);
        $this->assertStringContainsString('loc_alpha', $json);
        $this->assertStringNotContainsString($token, $json);
        $this->assertStringNotContainsString('private_integration_token', $json);
    }

    public function test_integration_permissions_are_not_granted_to_tenant_roles(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->assertDatabaseHas('permissions', ['slug' => 'integrations.view']);
        $this->assertDatabaseHas('permissions', ['slug' => 'integrations.manage']);

        foreach (['owner', 'manager', 'staff'] as $slug) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();
            $this->assertFalse($role->permissions()->whereIn('slug', ['integrations.view', 'integrations.manage'])->exists());
        }
    }

    public function test_each_new_lifecycle_transition_queues_one_sync_job(): void
    {
        Queue::fake();
        $workspace = $this->workspace('alpha.test');
        $tenantId = $workspace['tenant']->id;
        $this->link($tenantId, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');

        $booking = $this->booking($tenantId, 'Ada Lovelace', 'ada@example.com', '312-555-0100', status: BookingStatus::Held);
        $this->assertSame(['booking.created'], $this->kinds($booking->id));

        $booking->status = BookingStatus::Confirmed;
        $booking->save();
        $this->assertKindOnce('booking.confirmed', $tenantId, $booking->id);
        $this->assertSame(0, $this->kindCount('booking.changed', $booking->id));

        $booking->forceFill([
            'operational_status' => OperationalStatus::CheckedIn,
            'checked_in_at' => '2026-06-16 15:05:00',
        ])->save();
        $this->assertKindOnce('booking.checked_in', $tenantId, $booking->id);
        $this->assertSame(0, $this->kindCount('booking.changed', $booking->id));

        $booking->operational_status = OperationalStatus::Returned;
        $booking->save();
        $this->assertKindOnce('booking.returned', $tenantId, $booking->id);

        $booking->operational_status = OperationalStatus::Overdue;
        $booking->save();
        $this->assertKindOnce('booking.overdue', $tenantId, $booking->id);

        $booking->operational_status = OperationalStatus::Completed;
        $booking->save();
        $this->assertKindOnce('booking.completed', $tenantId, $booking->id);
        $this->assertSame(0, $this->kindCount('booking.changed', $booking->id));

        $booking->operational_status = OperationalStatus::Completed;
        $booking->save();
        $this->assertKindOnce('booking.completed', $tenantId, $booking->id);

        $booking->operational_status = OperationalStatus::InProgress;
        $booking->save();
        $this->assertKindOnce('booking.changed', $tenantId, $booking->id);

        $combined = $this->booking($tenantId, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Combined rental', status: BookingStatus::Held);
        $combined->forceFill([
            'status' => BookingStatus::Confirmed,
            'operational_status' => OperationalStatus::CheckedIn,
            'participants' => 4,
        ])->save();
        $this->assertKindOnce('booking.confirmed', $tenantId, $combined->id);
        $this->assertKindOnce('booking.checked_in', $tenantId, $combined->id);
        $this->assertKindOnce('booking.changed', $tenantId, $combined->id);

        $cancelled = $this->booking($tenantId, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Cancel rental');
        $cancelled->forceFill([
            'status' => BookingStatus::Cancelled,
            'operational_status' => OperationalStatus::Returned,
            'participants' => 3,
        ])->save();
        $this->assertKindOnce('booking.cancelled', $tenantId, $cancelled->id);
        $this->assertSame(0, $this->kindCount('booking.returned', $cancelled->id));
        $this->assertSame(0, $this->kindCount('booking.changed', $cancelled->id));

        $pending = Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'pending',
            'amount_cents' => 5000,
            'fee_cents' => 0,
        ]);
        $this->assertSame(0, $this->kindCount('payment.failed', $booking->id));
        $pending->status = 'failed';
        $pending->save();
        $failed = $this->jobs($booking->id)->filter(fn (SyncBookingToGoHighLevel $job): bool => $job->kind === 'payment.failed');
        $this->assertCount(1, $failed);
        $this->assertSame($pending->id, $failed->first()->paymentId);
        $this->assertSame($tenantId, $failed->first()->tenantId);
        $pending->amount_cents = 5100;
        $pending->save();
        $this->assertSame(1, $this->kindCount('payment.failed', $booking->id));
        $this->assertSame(0, $this->kindCount('payment.succeeded', $booking->id));
    }

    public function test_a_failed_checkout_queues_one_payment_failed_sync(): void
    {
        Queue::fake();
        $this->app->instance(StripeGateway::class, new FakeStripeGateway);
        $workspace = $this->workspace('alpha.test');
        $tenantId = $workspace['tenant']->id;
        $this->link($tenantId, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        $booking = $this->booking($tenantId, 'Ada Lovelace', 'ada@example.com', '312-555-0100');
        $payment = Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'pending',
            'amount_cents' => 5000,
            'fee_cents' => 150,
            'stripe_checkout_session_id' => 'cs_fail_1',
        ]);
        $session = [
            'id' => 'cs_fail_1',
            'payment_status' => 'unpaid',
            'metadata' => [
                'booking_id' => $booking->id,
                'tenant_id' => $tenantId,
            ],
        ];

        $this->postWebhook('evt_fail', $session)->assertOk();
        $this->postWebhook('evt_fail', $session)->assertOk();
        $this->postWebhook('evt_fail_again', $session)->assertOk();

        $failed = $this->jobs($booking->id)->filter(fn (SyncBookingToGoHighLevel $job): bool => $job->kind === 'payment.failed');
        $this->assertCount(1, $failed);
        $this->assertSame($payment->id, $failed->first()->paymentId);
        $this->assertSame($tenantId, $failed->first()->tenantId);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(0, $this->kindCount('payment.succeeded', $booking->id));
        $this->assertSame(0, $this->kindCount('booking.changed', $booking->id));
    }

    public function test_lifecycle_events_are_not_queued_without_a_link(): void
    {
        Queue::fake();
        $workspace = $this->workspace('alpha.test');
        $tenantId = $workspace['tenant']->id;
        $booking = $this->booking($tenantId, 'Ada Lovelace', 'ada@example.com', '312-555-0100', status: BookingStatus::Held);
        $booking->status = BookingStatus::Confirmed;
        $booking->save();

        foreach ([OperationalStatus::CheckedIn, OperationalStatus::Returned, OperationalStatus::Overdue, OperationalStatus::Completed, OperationalStatus::InProgress] as $status) {
            $booking->operational_status = $status;
            $booking->save();
        }

        $booking->participants = 4;
        $booking->save();
        $booking->status = BookingStatus::Cancelled;
        $booking->save();
        Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'failed',
            'amount_cents' => 5000,
            'fee_cents' => 0,
        ]);
        app(TenantManager::class)->set($workspace['tenant']);

        try {
            app(BookingEvents::class)->paymentFailed($tenantId, $booking->id, 'payment.failed:cs_unlinked');
        } finally {
            app(TenantManager::class)->forget();
        }

        Queue::assertNothingPushed();
        $this->assertSame(0, GhlSyncLog::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_lifecycle_tags_stay_on_that_workspaces_location(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaToken = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $this->link($alpha['tenant']->id, 'loc_alpha', $alphaToken);
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        $this->fakeContactApi('loc_alpha');

        $betaBooking = $this->booking($beta['tenant']->id, 'Bea Other', 'bea@example.com', '312-555-0199', 'Other rental');
        $booking = $this->booking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Sunset rental', status: BookingStatus::Held);
        $booking->status = BookingStatus::Confirmed;
        $booking->save();
        $booking->operational_status = OperationalStatus::CheckedIn;
        $booking->save();
        $booking->operational_status = OperationalStatus::Returned;
        $booking->save();
        $booking->operational_status = OperationalStatus::Overdue;
        $booking->save();
        $booking->operational_status = OperationalStatus::Completed;
        $booking->save();
        $payment = Payment::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $booking->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'pending',
            'amount_cents' => 5000,
            'fee_cents' => 0,
        ]);
        $payment->status = 'failed';
        $payment->save();

        $alphaTags = [];
        $notes = [];

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];
            $authorization = implode(' ', (array) $request->header('Authorization'));
            $raw = $request->url().' '.$authorization.' '.$request->body();
            $alphaRequest = str_contains($authorization, $alphaToken);
            $betaRequest = str_contains($authorization, $betaToken);

            $this->assertTrue($alphaRequest || $betaRequest);

            if (str_ends_with($request->url(), '/contacts/upsert')) {
                if ($alphaRequest) {
                    $this->assertSame('loc_alpha', $request->data()['locationId']);
                    $this->assertSame('ada@example.com', $request->data()['email']);
                }

                if ($betaRequest) {
                    $this->assertSame('loc_beta', $request->data()['locationId']);
                    $this->assertSame('bea@example.com', $request->data()['email']);
                }
            }

            if ($alphaRequest) {
                $this->assertStringNotContainsString($betaToken, $raw);
                $this->assertStringNotContainsString('loc_beta', $raw);
                $this->assertStringNotContainsString('bea@example.com', $raw);
            }

            if ($betaRequest) {
                $this->assertStringNotContainsString($alphaToken, $raw);
                $this->assertStringNotContainsString('loc_alpha', $raw);
                $this->assertStringNotContainsString('ada@example.com', $raw);
            }

            if ($alphaRequest && str_contains($request->url(), '/tags')) {
                $alphaTags[] = $request->data()['tags'];
            }

            if ($alphaRequest && str_contains($request->url(), '/notes')) {
                $notes[] = (string) $request->data()['body'];
            }
        }

        $expected = [
            ['reserveworx', 'reserveworx-booking-created'],
            ['reserveworx', 'reserveworx-booking-confirmed'],
            ['reserveworx', 'reserveworx-booking-checked_in'],
            ['reserveworx', 'reserveworx-booking-returned'],
            ['reserveworx', 'reserveworx-booking-overdue'],
            ['reserveworx', 'reserveworx-booking-completed'],
            ['reserveworx', 'reserveworx-payment-failed'],
        ];

        foreach ($expected as $tags) {
            $this->assertSame(1, count(array_filter($alphaTags, fn (array $sent): bool => $sent === $tags)), implode(',', $tags));
        }

        $this->assertSame(count($expected), count($alphaTags));
        $this->assertNotContains(['reserveworx', 'reserveworx-booking-changed'], $alphaTags);
        $joined = implode("\n", $notes);
        $this->assertStringContainsString('reserveworx:booking.completed:'.$booking->id.':', $joined);
        $this->assertStringContainsString('reserveworx:payment.failed:'.$booking->id.':'.$payment->id, $joined);
        $this->assertStringContainsString('reserveworx:booking.confirmed:'.$booking->id.':', $joined);

        Http::fake();
        Http::preventStrayRequests();
        GhlSyncLog::withoutGlobalScope(TenantScope::class)->delete();
        app()->call([new SyncBookingToGoHighLevel($alpha['tenant']->id, $betaBooking->id, 'booking.completed'), 'handle']);

        Http::assertNothingSent();
        $log = GhlSyncLog::withoutGlobalScope(TenantScope::class)->firstOrFail();
        $this->assertSame($alpha['tenant']->id, $log->tenant_id);
        $this->assertSame('booking.completed', $log->kind);
        $this->assertSame('failed', $log->status);
        $this->assertNull($log->booking_id);
        $this->assertStringNotContainsString('bea@example.com', (string) $log->error);
        $this->assertStringNotContainsString($betaToken, (string) DB::table('ghl_sync_logs')->get()->toJson());
    }

    public function test_later_sends_update_the_stored_contact_for_that_workspace_only(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaToken = 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04';
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $this->link($alpha['tenant']->id, 'loc_alpha', $alphaToken);
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/contacts/upsert')) {
                $location = $request->data()['locationId'] ?? '';
                $id = $location === 'loc_beta' ? 'contact-beta' : 'contact-alpha';

                return Http::response([
                    'new' => true,
                    'contact' => ['id' => $id, 'locationId' => is_string($location) ? $location : 'loc_alpha'],
                ], 200);
            }

            if ($request->method() === 'PUT') {
                $id = basename($request->url());
                $location = $id === 'contact-beta' ? 'loc_beta' : 'loc_alpha';

                return Http::response([
                    'succeeded' => true,
                    'contact' => ['id' => $id, 'locationId' => $location],
                ], 200);
            }

            if (str_contains($request->url(), '/tags')) {
                return Http::response(['tags' => []], 201);
            }

            return Http::response(['id' => 'note-1'], 201);
        });

        $customer = Customer::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
            'feedback_opted_out_at' => '2026-06-01 12:00:00',
        ]);
        $booking = $this->booking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Sunset rental', $customer);
        PricingSnapshot::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0',
            'payload' => ['channel' => 'third_party'],
        ]);
        $booking->participants = 3;
        $booking->save();

        $betaCustomer = Customer::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
        ]);
        $this->booking($beta['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Other rental', $betaCustomer);

        $upserts = 0;
        $puts = [];
        $changedNote = '';

        foreach (Http::recorded() as $pair) {
            /** @var Request $request */
            $request = $pair[0];

            if (str_ends_with($request->url(), '/contacts/upsert')) {
                $upserts++;
            }

            if ($request->method() === 'PUT') {
                $puts[] = $request;
            }

            if (str_contains($request->url(), '/notes') && str_contains((string) $request->data()['body'], 'booking.changed')) {
                $changedNote = (string) $request->data()['body'];
            }
        }

        $this->assertSame(2, $upserts);
        $this->assertCount(1, $puts);
        $this->assertStringEndsWith('/contacts/contact-alpha', $puts[0]->url());
        $this->assertTrue($puts[0]->hasHeader('Authorization', 'Bearer '.$alphaToken));
        $this->assertSame('ada@example.com', $puts[0]->data()['email']);
        $this->assertSame('ReserveWorx', $puts[0]->data()['source']);
        $this->assertArrayNotHasKey('tags', $puts[0]->data());
        $this->assertArrayNotHasKey('locationId', $puts[0]->data());
        $this->assertStringContainsString('Reference '.BookingReference::short($booking->id), $changedNote);
        $this->assertStringContainsString('Attribution third_party', $changedNote);
        $this->assertStringContainsString('Consent feedback opted out', $changedNote);
        $this->assertStringContainsString('Source ReserveWorx', $changedNote);
        $this->assertDatabaseHas('ghl_mappings', [
            'tenant_id' => $alpha['tenant']->id,
            'customer_id' => $customer->id,
            'location_id' => 'loc_alpha',
            'contact_id' => 'contact-alpha',
        ]);
        $this->assertDatabaseHas('ghl_mappings', [
            'tenant_id' => $beta['tenant']->id,
            'customer_id' => $betaCustomer->id,
            'location_id' => 'loc_beta',
            'contact_id' => 'contact-beta',
        ]);
        $this->assertSame(2, DB::table('ghl_mappings')->count());
        Http::assertNotSent(function (Request $request) use ($betaToken): bool {
            if (! $request->hasHeader('Authorization', 'Bearer '.$betaToken)) {
                return false;
            }

            return str_contains($request->url(), 'contact-alpha') || str_contains($request->body(), 'contact-alpha');
        });
    }

    public function test_a_stored_contact_that_highlevel_no_longer_has_is_created_again(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->link($workspace['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        $customer = Customer::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
        ]);
        GhlMapping::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'customer_id' => $customer->id,
            'location_id' => 'loc_alpha',
            'contact_id' => 'contact-gone',
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->method() === 'PUT') {
                return Http::response(['message' => 'not found'], 404);
            }

            if (str_ends_with($request->url(), '/contacts/upsert')) {
                return Http::response([
                    'new' => true,
                    'contact' => ['id' => 'contact-new', 'locationId' => 'loc_alpha'],
                ], 200);
            }

            if (str_contains($request->url(), '/tags')) {
                return Http::response(['tags' => []], 201);
            }

            return Http::response(['id' => 'note-1'], 201);
        });

        $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Sunset rental', $customer);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/contacts/contact-gone'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/contacts/contact-new/tags'));
        $this->assertDatabaseHas('ghl_mappings', [
            'tenant_id' => $workspace['tenant']->id,
            'customer_id' => $customer->id,
            'location_id' => 'loc_alpha',
            'contact_id' => 'contact-new',
        ]);
        $this->assertSame(1, DB::table('ghl_mappings')->count());
    }

    public function test_an_update_for_another_location_is_not_tagged(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->link($workspace['tenant']->id, 'loc_alpha', 'pit-alpha-DO-NOT-LEAK-9f3c2a7b1e04');
        $customer = Customer::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
        ]);
        GhlMapping::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'customer_id' => $customer->id,
            'location_id' => 'loc_alpha',
            'contact_id' => 'contact-foreign',
        ]);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            if ($request->method() === 'PUT') {
                return Http::response([
                    'succeeded' => true,
                    'contact' => ['id' => 'contact-foreign', 'locationId' => 'loc_other'],
                ], 200);
            }

            if (str_ends_with($request->url(), '/contacts/upsert')) {
                return Http::response([
                    'new' => true,
                    'contact' => ['id' => 'contact-safe', 'locationId' => 'loc_alpha'],
                ], 200);
            }

            if (str_contains($request->url(), '/tags')) {
                return Http::response(['tags' => []], 201);
            }

            return Http::response(['id' => 'note-1'], 201);
        });

        $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com', '312-555-0100', 'Sunset rental', $customer);

        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/contacts/contact-foreign/tags'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/contacts/contact-safe/tags'));
        $this->assertDatabaseHas('ghl_mappings', [
            'tenant_id' => $workspace['tenant']->id,
            'customer_id' => $customer->id,
            'contact_id' => 'contact-safe',
            'location_id' => 'loc_alpha',
        ]);
        $this->assertDatabaseMissing('ghl_mappings', ['contact_id' => 'contact-foreign']);
    }

    private function platformAdmin(): User
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function platform(string $tenantId): string
    {
        return 'http://localhost/api/v1/platform/tenants/'.$tenantId.'/ghl';
    }

    private function link(string $tenantId, string $locationId, string $token): void
    {
        GhlConnection::factory()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'private_integration_token' => $token,
        ]);
    }

    private function fakeContactApi(string $locationId): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($locationId) {
            if (str_ends_with($request->url(), '/contacts/upsert')) {
                $sent = $request->data()['locationId'] ?? $locationId;

                return Http::response([
                    'new' => true,
                    'contact' => ['id' => 'contact-1', 'locationId' => is_string($sent) && $sent !== '' ? $sent : $locationId],
                ], 200);
            }

            if (str_contains($request->url(), '/tags')) {
                return Http::response(['tags' => []], 201);
            }

            return Http::response(['id' => 'note-1'], 201);
        });
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function postWebhook(string $id, array $session): TestResponse
    {
        return $this->postJson('http://localhost/api/v1/stripe/webhook', [
            'id' => $id,
            'type' => 'checkout.session.async_payment_failed',
            'account' => 'acct_test_123',
            'data' => ['object' => $session],
        ], ['Stripe-Signature' => 'test-signature']);
    }

    /**
     * @return Collection<int, SyncBookingToGoHighLevel>
     */
    private function jobs(?string $bookingId = null): Collection
    {
        return Queue::pushed(SyncBookingToGoHighLevel::class)
            ->filter(function (SyncBookingToGoHighLevel $job) use ($bookingId): bool {
                return $bookingId === null || $job->bookingId === $bookingId;
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    private function kinds(?string $bookingId = null): array
    {
        return $this->jobs($bookingId)
            ->map(fn (SyncBookingToGoHighLevel $job): string => $job->kind)
            ->values()
            ->all();
    }

    private function kindCount(string $kind, ?string $bookingId = null): int
    {
        return $this->jobs($bookingId)
            ->filter(fn (SyncBookingToGoHighLevel $job): bool => $job->kind === $kind)
            ->count();
    }

    private function assertKindOnce(string $kind, string $tenantId, ?string $bookingId = null): void
    {
        $matches = $this->jobs($bookingId)->filter(function (SyncBookingToGoHighLevel $job) use ($kind, $tenantId): bool {
            return $job->kind === $kind && $job->tenantId === $tenantId;
        });

        $this->assertCount(1, $matches, $kind);
    }

    private function booking(
        string $tenantId,
        string $name,
        ?string $email,
        ?string $phone,
        string $serviceName = 'Kayak rental',
        ?Customer $customer = null,
        BookingStatus $status = BookingStatus::Confirmed,
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
        $customer ??= Customer::factory()->create([
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
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 2,
            'payload_hash' => hash('sha256', $tenantId.$serviceName.$email.$phone),
        ]);
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
