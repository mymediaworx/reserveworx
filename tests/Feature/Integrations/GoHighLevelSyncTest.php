<?php

namespace Tests\Feature\Integrations;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Jobs\SyncBookingToGoHighLevel;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GhlConnection;
use App\Models\GhlSyncLog;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Integrations\GhlRequestFailed;
use App\Tenancy\TenantScope;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

            if (str_contains($request->url(), '/tags')) {
                $tags[] = $request->data();
            }

            if (str_contains($request->url(), '/notes')) {
                $notes[] = $request->data();
            }
        }

        $this->assertCount(4, $upserts);
        $this->assertCount(4, $tags);
        $this->assertCount(4, $notes);

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
        $this->assertStringContainsString('Service Sunset rental', implode("\n", $bodies));
        $this->assertStringContainsString('Starts 2026-06-16 15:00:00 UTC', implode("\n", $bodies));
        $this->assertStringContainsString('reserveworx:booking.created:'.$booking->id.':', implode("\n", $bodies));
        $this->assertStringContainsString('reserveworx:payment.succeeded:'.$booking->id.':'.$payment->id, implode("\n", $bodies));

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
        $booking->operational_status = OperationalStatus::CheckedIn;
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
        Http::fake([
            'https://services.leadconnectorhq.com/contacts/upsert' => Http::response([
                'new' => true,
                'contact' => ['id' => 'contact-1', 'locationId' => $locationId],
            ], 200),
            'https://services.leadconnectorhq.com/contacts/contact-1/tags' => Http::response(['tags' => []], 201),
            'https://services.leadconnectorhq.com/contacts/contact-1/notes' => Http::response(['id' => 'note-1'], 201),
        ]);
    }

    private function booking(
        string $tenantId,
        string $name,
        ?string $email,
        ?string $phone,
        string $serviceName = 'Kayak rental',
        ?Customer $customer = null,
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
            'status' => BookingStatus::Confirmed,
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
