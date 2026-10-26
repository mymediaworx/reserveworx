<?php

namespace Tests\Feature\Integrations;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Jobs\SendGhlBookingMessage;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\GhlConnection;
use App\Models\GhlMessageLog;
use App\Models\GhlSyncLog;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Refund;
use App\Models\Service;
use App\Services\Integrations\IntegrationRecovery;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class IntegrationRetryTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_stopped_contact_sync_is_sent_again_and_the_booking_stays_put(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $token = 'pit-retry-DO-NOT-LEAK-9f3c2a7b1e04';
        $betaToken = 'pit-beta-DO-NOT-LEAK-1188aa44cc00';
        $booking = $this->booking($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com');
        $foreign = $this->booking($beta['tenant']->id, 'Bea Other', 'bea@example.com');
        $log = $this->failedSync($alpha['tenant']->id, $booking->id, 'booking.created', 5);
        $changed = $this->failedSync($alpha['tenant']->id, $booking->id, 'booking.changed', 5);
        $foreignLog = $this->failedSync($beta['tenant']->id, $foreign->id, 'booking.created', 5);
        $this->link($alpha['tenant']->id, 'loc_alpha', $token);
        $this->link($beta['tenant']->id, 'loc_beta', $betaToken);
        $this->fakeContactApi('loc_alpha');
        $before = $this->snapshot($booking);

        $this->actingAs($alpha['user']);
        $this->get($this->tenantUrl('alpha.test', '/admin/integrations'))
            ->assertOk()
            ->assertSee('Stopped sends')
            ->assertSee('booking.created')
            ->assertSee('Try again')
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee($token)
            ->assertDontSee('bea@example.com')
            ->assertDontSee($betaToken);
        $this->post($this->tenantUrl('alpha.test', '/admin/integrations/ghl-syncs/'.$changed->id.'/retry'))
            ->assertRedirect('/admin/integrations')
            ->assertSessionHas('status', 'That send was queued again.');
        $this->get($this->tenantUrl('alpha.test', '/admin/integrations'))
            ->assertOk()
            ->assertSee('That send was queued again.')
            ->assertDontSee($token);

        $this->actingAsWorkspace($alpha);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-syncs/'.$log->id.'/retry'))
            ->assertStatus(202)
            ->assertJsonPath('data.id', $log->id)
            ->assertJsonPath('data.accepted', true)
            ->assertDontSee($token);

        Http::assertSent(function (Request $request) use ($token): bool {
            return str_contains($request->url(), '/contacts/upsert')
                && $request->hasHeader('Authorization', 'Bearer '.$token)
                && $request->data()['locationId'] === 'loc_alpha'
                && $request->data()['email'] === 'ada@example.com';
        });
        Http::assertNotSent(function (Request $request) use ($betaToken): bool {
            return str_contains($request->url().' '.$request->body(), $betaToken)
                || str_contains($request->body(), 'bea@example.com');
        });

        $this->assertBookingUnchanged($booking, $before);
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertDatabaseHas('ghl_sync_logs', [
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $booking->id,
            'status' => 'skipped',
            'error' => IntegrationRecovery::RETRY_QUEUED,
        ]);
        $this->assertDatabaseHas('ghl_sync_logs', [
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $booking->id,
            'kind' => 'booking.created',
            'status' => 'succeeded',
        ]);

        $audit = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'ghl_sync.retried')
            ->where('subject_id', $log->id)
            ->firstOrFail();
        $this->assertSame($alpha['user']->id, $audit->actor_user_id);
        $this->assertSame(2, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'ghl_sync.retried')->count());
        $this->assertStringNotContainsString($token, (string) json_encode($audit->properties));

        $sent = count(Http::recorded());
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-syncs/'.$log->id.'/retry'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'A later try is already recorded.');
        $this->assertCount($sent, Http::recorded());
        $this->assertBookingUnchanged($booking, $before);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-syncs/'.$foreignLog->id.'/retry'))
            ->assertNotFound();
        $foreignLog = GhlSyncLog::withoutGlobalScope(TenantScope::class)->findOrFail($foreignLog->id);
        $this->assertSame('failed', $foreignLog->status);
        $this->assertSame(5, $foreignLog->attempt);
    }

    public function test_a_sync_still_in_retry_or_without_a_link_is_refused(): void
    {
        $workspace = $this->workspace('alpha.test');
        $booking = $this->booking($workspace['tenant']->id, 'Ada Lovelace', 'ada@example.com');
        $early = $this->failedSync($workspace['tenant']->id, $booking->id, 'booking.created', 3);
        $this->link($workspace['tenant']->id, 'loc_alpha', 'pit-retry-DO-NOT-LEAK-9f3c2a7b1e04');
        Http::preventStrayRequests();
        Http::fake();
        $this->actingAsWorkspace($workspace);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-syncs/'.$early->id.'/retry'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This sync is still retrying.');
        Http::assertNothingSent();
        $this->assertSame(0, GhlSyncLog::withoutGlobalScope(TenantScope::class)->where('status', 'skipped')->count());

        $unlinked = $this->workspace('beta.test');
        $other = $this->booking($unlinked['tenant']->id, 'Bea Other', 'bea@example.com');
        $stopped = $this->failedSync($unlinked['tenant']->id, $other->id, 'booking.cancelled', 5);
        $this->actingAsWorkspace($unlinked);
        Http::fake();

        $this->postJson($this->tenantUrl('beta.test', '/api/v1/integrations/ghl-syncs/'.$stopped->id.'/retry'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'GoHighLevel is not linked.');
        Http::assertNothingSent();
        $stopped = GhlSyncLog::withoutGlobalScope(TenantScope::class)->findOrFail($stopped->id);
        $other = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($other->id);
        $this->assertSame('failed', $stopped->status);
        $this->assertSame(BookingStatus::Confirmed, $other->status);
    }

    public function test_a_stopped_message_is_queued_again_and_staff_cannot_send_it(): void
    {
        $owner = $this->workspace('alpha.test');
        $staff = $this->workspace('staff.test', 'staff');
        $beta = $this->workspace('beta.test');
        $token = 'pit-message-DO-NOT-LEAK-9f3c2a7b1e04';
        $booking = $this->booking($owner['tenant']->id, 'Ada Lovelace', 'ada@example.com');
        $staffBooking = $this->booking($staff['tenant']->id, 'Cam Staff', 'cam@example.com');
        $foreign = $this->booking($beta['tenant']->id, 'Bea Other', 'bea@example.com');
        $log = $this->failedMessage($owner['tenant']->id, $booking->id);
        $early = $this->failedMessage($owner['tenant']->id, $booking->id, 2, 'booking.reminder');
        $staffLog = $this->failedMessage($staff['tenant']->id, $staffBooking->id);
        $foreignLog = $this->failedMessage($beta['tenant']->id, $foreign->id);
        $this->link($owner['tenant']->id, 'loc_alpha', $token);
        $this->link($staff['tenant']->id, 'loc_staff', 'pit-staff-DO-NOT-LEAK-aabbccddeeff');
        $before = $this->snapshot($booking);
        Http::preventStrayRequests();

        $this->actingAs($staff['user']);
        $this->get($this->tenantUrl('staff.test', '/admin/integrations'))
            ->assertOk()
            ->assertSee('booking.confirmed')
            ->assertDontSee('Try again');
        $this->actingAsWorkspace($staff);
        $this->postJson($this->tenantUrl('staff.test', '/api/v1/integrations/ghl-messages/'.$staffLog->id.'/retry'))
            ->assertForbidden();
        $staffLog = GhlMessageLog::withoutGlobalScope(TenantScope::class)->findOrFail($staffLog->id);
        $this->assertSame('failed', $staffLog->status);
        $this->assertSame(0, DB::table('jobs')->count());

        $this->actingAsWorkspace($owner);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-messages/'.$early->id.'/retry'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This message is still retrying.');
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-messages/'.$foreignLog->id.'/retry'))
            ->assertNotFound();

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-messages/'.$log->id.'/retry'))
            ->assertStatus(202)
            ->assertJsonPath('data.accepted', true)
            ->assertDontSee($token);
        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->findOrFail($log->id);
        $this->assertSame('queued', $log->status);
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertBookingUnchanged($booking, $before);
        Http::assertNothingSent();

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-messages/'.$log->id.'/retry'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This message is already queued.');
        $this->assertSame(1, DB::table('jobs')->count());

        $this->fakeMessageApi('loc_alpha', 'msg-retry');
        app()->call([new SendGhlBookingMessage($owner['tenant']->id, $log->id), 'handle']);

        $log = GhlMessageLog::withoutGlobalScope(TenantScope::class)->findOrFail($log->id);
        $this->assertSame('succeeded', $log->status);
        $this->assertSame('msg-retry', $log->ghl_message_id);
        $this->assertBookingUnchanged($booking, $before);
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        Http::assertSent(function (Request $request) use ($token): bool {
            return $request->url() === 'https://services.leadconnectorhq.com/conversations/messages'
                && $request->hasHeader('Authorization', 'Bearer '.$token)
                && $request->data()['emailTo'] === 'ada@example.com';
        });

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/integrations/ghl-messages/'.$log->id.'/retry'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'This message is not waiting on a retry.');

        $audit = AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'ghl_message.retried')->firstOrFail();
        $this->assertSame($owner['user']->id, $audit->actor_user_id);
        $this->assertStringNotContainsString($token, (string) json_encode($audit->properties));
        $foreignLog = GhlMessageLog::withoutGlobalScope(TenantScope::class)->findOrFail($foreignLog->id);
        $this->assertSame('failed', $foreignLog->status);
    }

    private function link(string $tenantId, string $locationId, string $token): void
    {
        GhlConnection::factory()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'private_integration_token' => $token,
        ]);
    }

    private function failedSync(string $tenantId, string $bookingId, string $kind, int $attempt): GhlSyncLog
    {
        return GhlSyncLog::factory()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'payment_id' => null,
            'kind' => $kind,
            'attempt' => $attempt,
            'status' => 'failed',
            'http_status' => 503,
            'error' => 'GoHighLevel upsert returned HTTP 503.',
            'location_id' => 'loc_alpha',
        ]);
    }

    private function failedMessage(string $tenantId, string $bookingId, int $attempts = 5, string $kind = 'booking.confirmed'): GhlMessageLog
    {
        return GhlMessageLog::factory()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'kind' => $kind,
            'dedupe_key' => $kind.':'.$bookingId,
            'status' => 'failed',
            'attempts' => $attempts,
            'http_status' => 503,
            'error' => 'GoHighLevel message returned HTTP 503.',
        ]);
    }

    private function booking(string $tenantId, string $name, string $email): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
        $customer = Customer::factory()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
            'phone' => '312-555-0100',
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
            'payload_hash' => hash('sha256', $tenantId.$email),
        ]);
    }

    /**
     * @return array{status: string, payment_status: string, participants: int, starts_at: string, email: string}
     */
    private function snapshot(Booking $booking): array
    {
        $fresh = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($booking->id);
        $customer = Customer::withoutGlobalScope(TenantScope::class)->find($fresh->customer_id);

        return [
            'status' => $fresh->status->value,
            'payment_status' => $fresh->payment_status->value,
            'participants' => $fresh->participants,
            'starts_at' => $fresh->starts_at->utc()->toIso8601String(),
            'email' => $customer instanceof Customer ? (string) $customer->email : '',
        ];
    }

    /**
     * @param  array{status: string, payment_status: string, participants: int, starts_at: string, email: string}  $before
     */
    private function assertBookingUnchanged(Booking $booking, array $before): void
    {
        $booking->refresh();
        $this->assertSame($before, $this->snapshot($booking));
    }

    private function fakeContactApi(string $locationId): void
    {
        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($locationId) {
            if (str_ends_with($request->url(), '/contacts/upsert')) {
                return Http::response([
                    'new' => true,
                    'contact' => ['id' => 'contact-1', 'locationId' => $locationId],
                ], 200);
            }

            if (str_contains($request->url(), '/tags')) {
                return Http::response(['tags' => []], 201);
            }

            return Http::response(['id' => 'note-1'], 201);
        });
    }

    private function fakeMessageApi(string $locationId, string $messageId): void
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
}
