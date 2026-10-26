<?php

namespace Tests\Feature\Customers;

use App\Enums\BookingStatus;
use App\Enums\CustomerDataExportStatus;
use App\Enums\OperationalStatus;
use App\Enums\OperatorFeePricing;
use App\Enums\OperatorFeeScope;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Enums\WaitlistStatus;
use App\Jobs\BuildCustomerDataExport;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingOperatorFee;
use App\Models\BookingReminder;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerDataDeletion;
use App\Models\CustomerDataExport;
use App\Models\CustomerSignInToken;
use App\Models\GhlConnection;
use App\Models\GhlMessageLog;
use App\Models\GhlSyncLog;
use App\Models\Incident;
use App\Models\IntegrationOutbox;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\OperatorFee;
use App\Models\Participant;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Services\Customers\CustomerAccountLinker;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;
use ZipArchive;

class CustomerPrivacyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_staff_cannot_export_or_delete_and_a_queued_export_writes_no_file(): void
    {
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('other.test');
        $staff = $this->member($workspace['tenant'], 'staff');
        $manager = $this->member($workspace['tenant'], 'manager');
        $customer = $this->customer($workspace['tenant']->id, 'Ada Kayak', 'ada@example.com');
        $foreign = $this->customer($other['tenant']->id, 'Ada Other', 'ada-other@example.com');

        Storage::fake('local');
        Storage::fake('public');
        Queue::fake();

        Sanctum::actingAs($staff);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/exports'))
            ->assertForbidden();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/deletion'), [
            'confirm' => true,
        ])->assertForbidden();

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$foreign->id.'/exports'))
            ->assertNotFound();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$foreign->id.'/deletion'), [
            'confirm' => true,
        ])->assertNotFound();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/deletion'))
            ->assertStatus(409)
            ->assertJsonPath('message', 'Deletion is refused until a customer data export exists.');

        Sanctum::actingAs($manager);
        $queued = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/exports'))
            ->assertStatus(202)
            ->assertJsonPath('data.customer_id', $customer->id)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.download_url', null);

        Queue::assertPushed(BuildCustomerDataExport::class, function (BuildCustomerDataExport $job) use ($workspace, $queued): bool {
            return $job->tenantId === $workspace['tenant']->id
                && $job->exportId === $queued->json('data.id');
        });
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->within($workspace['tenant'], function () use ($customer): void {
            $this->assertNull($customer->fresh()?->anonymized_at);
            $this->assertSame('Ada Kayak', $customer->fresh()?->name);
            $this->assertTrue(
                AuditLog::query()->where('action', 'customer.data_deletion_refused')->where('subject_id', $customer->id)->exists(),
            );
            $this->assertSame(0, CustomerDataDeletion::query()->count());
        });
        $untouched = Customer::withoutGlobalScope(TenantScope::class)->findOrFail($foreign->id);
        $this->assertSame('Ada Other', $untouched->name);
        $this->assertSame('ada-other@example.com', $untouched->email);
    }

    public function test_a_missing_export_file_does_not_unlock_deletion_and_confirm_does(): void
    {
        $workspace = $this->workspace('dock.test');
        $tenantId = $workspace['tenant']->id;
        $refused = $this->customer($tenantId, 'Ada Kayak', 'ada@example.com', '312-555-0100');
        $confirmed = $this->customer($tenantId, 'Bea Paddle', 'bea@example.com', '312-555-0199');
        Storage::fake('local');

        CustomerDataExport::query()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $refused->id,
            'actor_user_id' => $workspace['user']->id,
            'disk' => CustomerDataExport::DISK,
            'status' => CustomerDataExportStatus::Failed,
            'failed_at' => now(),
        ]);
        CustomerDataExport::query()->create([
            'tenant_id' => $tenantId,
            'customer_id' => $refused->id,
            'actor_user_id' => $workspace['user']->id,
            'disk' => CustomerDataExport::DISK,
            'path' => 'customer-exports/'.$tenantId.'/missing.zip',
            'bytes' => 12,
            'status' => CustomerDataExportStatus::Completed,
            'completed_at' => now(),
        ]);

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$refused->id.'/deletion'), [
            'confirm' => false,
        ])->assertStatus(409);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$refused->id.'/deletion'), [
            'confirm' => 'yes',
        ])->assertStatus(422);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$confirmed->id.'/deletion'), [
            'confirm' => true,
        ])->assertOk()
            ->assertJsonPath('data.customer_id', $confirmed->id)
            ->assertJsonPath('data.idempotent', false);

        $this->within($workspace['tenant'], function () use ($refused, $confirmed): void {
            $this->assertNull($refused->fresh()?->anonymized_at);
            $this->assertSame('Ada Kayak', $refused->fresh()?->name);
            $this->assertSame('ada@example.com', $refused->fresh()?->email);

            $erased = $confirmed->fresh();
            $this->assertInstanceOf(Customer::class, $erased);
            $this->assertSame('Removed customer', $erased->name);
            $this->assertNull($erased->email);
            $this->assertNull($erased->phone);
            $this->assertNotNull($erased->anonymized_at);

            $deletion = CustomerDataDeletion::query()->where('customer_id', $confirmed->id)->firstOrFail();
            $this->assertTrue($deletion->confirmed);
            $this->assertNull($deletion->export_id);
            $this->assertSame(1, CustomerDataDeletion::query()->count());
        });
    }

    public function test_export_zip_is_private_and_the_signed_download_expires(): void
    {
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('other.test');
        $graph = $this->graph($workspace['tenant']->id, $workspace['user']->id);
        $foreign = $this->customer($other['tenant']->id, 'Ada Other', 'ada-other@example.com');
        $this->booking($other['tenant']->id, $foreign->id, 'other-kayak');

        Storage::fake('local');
        Storage::fake('public');
        $this->actingAsWorkspace($workspace);

        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$graph['customer']->id.'/exports'))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.row_counts.customer', 1)
            ->assertJsonPath('data.row_counts.bookings', 1)
            ->assertJsonPath('data.row_counts.payments', 1)
            ->assertJsonPath('data.row_counts.refunds', 1)
            ->assertJsonPath('data.row_counts.platform_fees', 1)
            ->assertJsonPath('data.row_counts.booking_operator_fees', 1)
            ->assertJsonPath('data.row_counts.booking_reminders', 1)
            ->assertJsonPath('data.row_counts.waiver_signatures', 1);

        $exportId = $created->json('data.id');
        $this->assertIsString($exportId);
        $downloadUrl = $created->json('data.download_url');
        $this->assertIsString($downloadUrl);
        $this->assertStringContainsString('http://dock.test/api/v1/customers/'.$graph['customer']->id.'/exports/'.$exportId.'/download', $downloadUrl);
        $this->assertStringContainsString('signature=', $downloadUrl);

        $export = $this->within($workspace['tenant'], function () use ($exportId, $workspace): CustomerDataExport {
            $export = CustomerDataExport::query()->findOrFail($exportId);
            $this->assertSame(CustomerDataExport::DISK, $export->disk);
            $this->assertSame(CustomerDataExportStatus::Completed, $export->status);
            $this->assertIsString($export->path);
            $this->assertStringStartsWith('customer-exports/'.$workspace['tenant']->id.'/', $export->path);
            $this->assertTrue(AuditLog::query()->where('action', 'customer.data_export_queued')->exists());
            $this->assertTrue(AuditLog::query()->where('action', 'customer.data_exported')->exists());

            return $export;
        });

        $this->assertIsString($export->path);
        Storage::disk('local')->assertExists($export->path);
        $this->assertSame([], Storage::disk('public')->allFiles());
        foreach (Storage::disk('local')->allFiles() as $path) {
            $this->assertStringStartsWith('customer-exports/', $path);
        }

        $entries = $this->zipEntries($export->path);
        $this->assertSame('open', $entries['manifest.json']['waiver_retention']);
        $this->assertSame($workspace['tenant']->id, $entries['manifest.json']['tenant_id']);
        $this->assertSame($graph['customer']->id, $entries['customer.json']['id']);
        $this->assertSame('ada@example.com', $entries['customer.json']['email']);
        $this->assertSame($graph['booking']->id, $entries['bookings.json'][0]['id']);
        $this->assertSame(10_300, $entries['payments.json'][0]['amount_cents']);
        $this->assertSame(400, $entries['refunds.json'][0]['customer_refund_cents']);
        $this->assertSame(300, $entries['platform_fees.json'][0]['amount_cents']);
        $this->assertSame(500, $entries['booking_operator_fees.json'][0]['total_cents']);
        $this->assertSame(1440, $entries['booking_reminders.json'][0]['offset_minutes']);
        $this->assertSame('Ada Kayak', $entries['waiver_signatures.json'][0]['participant_name']);
        $this->assertSame('Ada@Example.com', $entries['waitlist_entries.json'][0]['customer_email']);
        $blob = json_encode($entries, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('bea@example.com', $blob);
        $this->assertStringNotContainsString('ada-other@example.com', $blob);
        $this->assertCount(1, $entries['payments.json']);

        $shown = $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$graph['customer']->id.'/exports/'.$exportId))
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertIsString($shown->json('data.download_url'));

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$graph['neighbor']->id.'/exports/'.$exportId))
            ->assertNotFound();

        $download = $this->get($downloadUrl)->assertOk();
        $this->assertStringContainsString('application/zip', (string) $download->headers->get('content-type'));
        $this->assertStringContainsString(
            'customer-'.$graph['customer']->id.'.zip',
            (string) $download->headers->get('content-disposition'),
        );

        $this->get($this->tenantUrl('dock.test', '/api/v1/customers/'.$graph['customer']->id.'/exports/'.$exportId.'/download'))
            ->assertForbidden();

        URL::forceRootUrl('http://dock.test');
        $mismatched = URL::temporarySignedRoute('customers.exports.download', now()->addMinutes(5), [
            'customer' => $graph['neighbor']->id,
            'export' => $exportId,
        ]);
        URL::forceRootUrl(null);
        $this->get($mismatched)->assertNotFound();

        $this->get(str_replace('http://dock.test', 'http://other.test', $downloadUrl))->assertNotFound();

        $this->travel(31)->minutes();
        $this->get($downloadUrl)->assertForbidden();
        $this->travelBack();
    }

    public function test_deletion_after_an_export_anonymizes_pii_and_keeps_financial_rows(): void
    {
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('other.test');
        $graph = $this->graph($workspace['tenant']->id, $workspace['user']->id);
        $foreign = $this->customer($other['tenant']->id, 'Ada Other', 'ada-other@example.com', '312-555-0100');
        Storage::fake('local');
        $this->actingAsWorkspace($workspace);

        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$graph['customer']->id.'/exports'))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed');
        $exportId = $created->json('data.id');
        $this->assertIsString($exportId);

        $path = $this->within($workspace['tenant'], function () use ($exportId): string {
            $path = CustomerDataExport::query()->findOrFail($exportId)->path;
            $this->assertIsString($path);

            return $path;
        });

        $erased = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$graph['customer']->id.'/deletion'))
            ->assertOk()
            ->assertJsonPath('data.customer_id', $graph['customer']->id)
            ->assertJsonPath('data.idempotent', false);
        $deletionId = $erased->json('data.id');
        $this->assertIsString($deletionId);

        $repeated = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$graph['customer']->id.'/deletion'))
            ->assertOk()
            ->assertJsonPath('data.id', $deletionId)
            ->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.anonymized_at', $erased->json('data.anonymized_at'));

        $this->within($workspace['tenant'], function () use ($graph, $exportId): void {
            $customer = $graph['customer']->fresh();
            $this->assertInstanceOf(Customer::class, $customer);
            $this->assertSame('Removed customer', $customer->name);
            $this->assertNull($customer->email);
            $this->assertNull($customer->phone);
            $this->assertNotNull($customer->anonymized_at);

            $booking = $graph['booking']->fresh();
            $this->assertInstanceOf(Booking::class, $booking);
            $this->assertSame($customer->id, $booking->customer_id);
            $this->assertSame(BookingStatus::Confirmed, $booking->status);
            $this->assertIsString($booking->staff_notes);
            $this->assertStringNotContainsString('Ada Kayak', $booking->staff_notes);
            $this->assertStringNotContainsString('ada@example.com', $booking->staff_notes);
            $this->assertStringNotContainsString('312-555-0100', $booking->staff_notes);
            $neighborBooking = Booking::query()->findOrFail($graph['neighborWaitlist']->booking_id);
            $this->assertSame('Bea likes the blue boat', $neighborBooking->staff_notes);

            $this->assertSame(10_300, $graph['payment']->fresh()?->amount_cents);
            $this->assertSame(400, $graph['refund']->fresh()?->customer_refund_cents);
            $this->assertSame(300, $graph['fee']->fresh()?->amount_cents);
            $this->assertSame(500, $graph['operatorFee']->fresh()?->total_cents);

            $participant = $graph['participant']->fresh();
            $this->assertInstanceOf(Participant::class, $participant);
            $this->assertSame('Removed', $participant->name);
            $this->assertNull($participant->guardian_name);

            $signature = $graph['signature']->fresh();
            $this->assertInstanceOf(WaiverSignature::class, $signature);
            $this->assertSame('Removed', $signature->participant_name);
            $this->assertNull($signature->guardian_name);
            $this->assertNull($signature->ip_address);
            $this->assertNull($signature->user_agent);
            $this->assertTrue($signature->is_minor);
            $this->assertSame($graph['hash'], $signature->content_hash);
            $this->assertSame('2026-06-01 12:00:00', $signature->signed_at?->format('Y-m-d H:i:s'));
            $this->assertSame(1, WaiverSignature::query()->count());

            $this->assertNull($graph['waitlist']->fresh()?->customer_name);
            $this->assertNull($graph['waitlist']->fresh()?->customer_email);
            $this->assertSame('Bea Paddle', $graph['neighbor']->fresh()?->name);
            $this->assertSame('bea@example.com', $graph['neighbor']->fresh()?->email);
            $this->assertSame('Bea Paddle', $graph['neighborWaitlist']->fresh()?->customer_name);

            $incident = $graph['incident']->fresh();
            $this->assertInstanceOf(Incident::class, $incident);
            $this->assertStringNotContainsString('Ada Kayak', $incident->summary);
            $this->assertStringNotContainsString('ada@example.com', $incident->summary);
            $this->assertStringNotContainsString('312-555-0100', $incident->summary);
            $this->assertStringNotContainsString('Ada Kayak', (string) $incident->notes);
            $this->assertStringNotContainsString('ada@example.com', (string) $incident->notes);
            $this->assertStringNotContainsString('312-555-0100', (string) $incident->notes);

            $hook = $graph['hook']->fresh();
            $this->assertInstanceOf(NotificationHook::class, $hook);
            $this->assertSame('Removed', $hook->payload['email'] ?? null);
            $this->assertIsString($hook->delivery_error);
            $this->assertStringNotContainsString('ada@example.com', $hook->delivery_error);

            $outbox = $graph['outbox']->fresh();
            $this->assertInstanceOf(IntegrationOutbox::class, $outbox);
            $this->assertSame('Removed', $outbox->payload['email'] ?? null);
            $this->assertSame('Bea Paddle mailbox failed', $graph['neighborOutbox']->fresh()?->last_error);

            $planted = AuditLog::query()->where('action', 'participant.added')->firstOrFail();
            $this->assertSame('203.0.113.10', $planted->ip_address);
            $this->assertSame('StaffBrowser/1.0', $planted->user_agent);
            $this->assertSame('Removed', $planted->properties['name'] ?? null);
            $this->assertSame('Removed', $planted->properties['email'] ?? null);

            $anonymized = AuditLog::query()->where('action', 'customer.data_anonymized')->firstOrFail();
            $this->assertSame('open', $anonymized->properties['waiver_retention'] ?? null);
            $this->assertSame(1, $anonymized->properties['waiver_rows_kept'] ?? null);
            $this->assertFalse($anonymized->properties['confirmed'] ?? true);
            $this->assertSame($exportId, $anonymized->properties['export_id'] ?? null);
            $this->assertTrue(AuditLog::query()->where('action', 'customer.data_anonymized_repeated')->exists());
            $this->assertSame(1, CustomerDataDeletion::query()->count());

            $deletion = CustomerDataDeletion::query()->firstOrFail();
            $this->assertFalse($deletion->confirmed);
            $this->assertSame($exportId, $deletion->export_id);
        });

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers?q=ada@example.com'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers?q=312-555-0100'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers?q=bea@example.com'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $graph['neighbor']->id);

        Storage::disk('local')->assertExists($path);
        $snapshot = json_encode($this->zipEntries($path), JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('ada@example.com', $snapshot);

        $foreignRow = Customer::withoutGlobalScope(TenantScope::class)->findOrFail($foreign->id);
        $this->assertSame('Ada Other', $foreignRow->name);
        $this->assertSame('ada-other@example.com', $foreignRow->email);
        $this->assertNull($foreignRow->anonymized_at);
        $this->assertSame($deletionId, $repeated->json('data.id'));
    }

    public function test_export_and_anonymizing_deletion_remove_an_exclusive_customer_account(): void
    {
        $workspace = $this->workspace('dock.test');
        $tenantId = $workspace['tenant']->id;
        $customer = $this->customer($tenantId, 'Ada Kayak', 'ada@example.com', '312-555-0100');
        $booking = $this->booking($tenantId, $customer->id, 'ada-kayak');
        $account = CustomerAccount::query()->create([
            'tenant_id' => $tenantId,
            'email' => 'Ada@example.com',
            'name' => 'Ada Kayak',
            'password' => 'correct-horse-battery',
        ]);
        $this->within($workspace['tenant'], function () use ($account, $customer): void {
            $account->forceFill(['email_verified_at' => now()])->save();
            $customer->forceFill(['customer_account_id' => $account->id])->save();
        });
        $tokenHash = hash('sha256', 'plain-sign-in-token-not-exported');
        CustomerSignInToken::query()->create([
            'tenant_id' => $tenantId,
            'customer_account_id' => $account->id,
            'token_hash' => $tokenHash,
            'expires_at' => now()->addHour(),
        ]);
        $plain = $account->createToken('customer')->plainTextToken;
        $passwordHash = $account->password;
        $this->assertIsString($passwordHash);
        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $workspace['user']->id,
            'action' => 'customer_auth.sign_in',
            'subject_type' => CustomerAccount::class,
            'subject_id' => $account->id,
            'properties' => ['email' => 'ada@example.com'],
            'created_at' => now(),
        ]);

        Storage::fake('local');
        $this->actingAsWorkspace($workspace);
        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/exports'))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.row_counts.customer_account', 1);
        $exportId = $created->json('data.id');
        $this->assertIsString($exportId);
        $path = $this->within($workspace['tenant'], function () use ($exportId): string {
            $path = CustomerDataExport::query()->findOrFail($exportId)->path;
            $this->assertIsString($path);

            return $path;
        });
        $entries = $this->zipEntries($path);
        $this->assertIsArray($entries['customer_account.json']);
        $this->assertSame($account->id, $entries['customer_account.json']['id']);
        $this->assertSame('ada@example.com', $entries['customer_account.json']['email']);
        $this->assertSame('Ada Kayak', $entries['customer_account.json']['name']);
        $this->assertArrayNotHasKey('password', $entries['customer_account.json']);
        $this->assertArrayNotHasKey('remember_token', $entries['customer_account.json']);
        $blob = json_encode($entries, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($passwordHash, $blob);
        $this->assertStringNotContainsString($tokenHash, $blob);
        $this->assertStringNotContainsString('correct-horse-battery', $blob);
        $this->assertStringContainsString('ada@example.com', $blob);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/deletion'))
            ->assertOk()
            ->assertJsonPath('data.idempotent', false);

        $this->app['auth']->forgetGuards();
        $this->withToken($plain)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings'))
            ->assertUnauthorized();
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customer/session'), [
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
        ])->assertUnprocessable();

        $this->within($workspace['tenant'], function () use ($customer, $booking): void {
            $erased = $customer->fresh();
            $this->assertInstanceOf(Customer::class, $erased);
            $this->assertSame('Removed customer', $erased->name);
            $this->assertNull($erased->email);
            $this->assertNull($erased->customer_account_id);
            $this->assertNotNull($erased->anonymized_at);
            $this->assertSame($erased->id, $booking->fresh()?->customer_id);
            $this->assertSame(0, CustomerAccount::query()->count());
            $this->assertSame(0, CustomerSignInToken::query()->count());
            $this->assertSame(0, DB::table('personal_access_tokens')->count());

            $signIn = AuditLog::query()->where('action', 'customer_auth.sign_in')->firstOrFail();
            $encoded = json_encode($signIn->properties, JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('ada@example.com', $encoded);

            $again = Customer::query()->create([
                'tenant_id' => $erased->tenant_id,
                'name' => 'Ada Kayak',
                'email' => 'ada@example.com',
            ]);
            app(CustomerAccountLinker::class)->attachGuestCustomer($again);
            $this->assertNull($again->fresh()?->customer_account_id);
        });
    }

    public function test_a_shared_customer_account_stays_when_another_customer_still_uses_it(): void
    {
        $workspace = $this->workspace('dock.test');
        $tenantId = $workspace['tenant']->id;
        $first = $this->customer($tenantId, 'Ada Kayak', 'ada@example.com');
        $second = $this->customer($tenantId, 'Ada Kayak', 'ada@example.com');
        $firstBooking = $this->booking($tenantId, $first->id, 'ada-first');
        $secondBooking = $this->booking($tenantId, $second->id, 'ada-second');
        $account = CustomerAccount::query()->create([
            'tenant_id' => $tenantId,
            'email' => 'ada@example.com',
            'name' => 'Ada Kayak',
            'password' => 'correct-horse-battery',
        ]);
        $this->within($workspace['tenant'], function () use ($account, $first, $second): void {
            $account->forceFill(['email_verified_at' => now()])->save();
            $first->forceFill(['customer_account_id' => $account->id])->save();
            $second->forceFill(['customer_account_id' => $account->id])->save();
        });
        CustomerSignInToken::query()->create([
            'tenant_id' => $tenantId,
            'customer_account_id' => $account->id,
            'token_hash' => hash('sha256', 'shared-sign-in-token'),
            'expires_at' => now()->addHour(),
        ]);
        $plain = $account->createToken('customer')->plainTextToken;

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$first->id.'/deletion'), [
            'confirm' => true,
        ])->assertOk()->assertJsonPath('data.idempotent', false);

        $this->app['auth']->forgetGuards();
        $listed = $this->withToken($plain)
            ->getJson($this->tenantUrl('dock.test', '/api/v1/customer/bookings'))
            ->assertOk();
        $this->assertSame([$secondBooking->id], collect($listed->json('past'))->pluck('id')->all());
        $this->assertNotContains($firstBooking->id, collect($listed->json('past'))->pluck('id')->all());

        $this->within($workspace['tenant'], function () use ($first, $second, $account): void {
            $erased = $first->fresh();
            $this->assertInstanceOf(Customer::class, $erased);
            $this->assertNull($erased->email);
            $this->assertNull($erased->customer_account_id);
            $kept = $second->fresh();
            $this->assertInstanceOf(Customer::class, $kept);
            $this->assertSame('ada@example.com', $kept->email);
            $this->assertSame($account->id, $kept->customer_account_id);
            $keptAccount = CustomerAccount::query()->find($account->id);
            $this->assertInstanceOf(CustomerAccount::class, $keptAccount);
            $this->assertSame('ada@example.com', $keptAccount->email);
            $this->assertNotNull($keptAccount->password);
            $this->assertSame(1, CustomerSignInToken::query()->count());
            $this->assertSame(1, DB::table('personal_access_tokens')->count());
        });
    }

    public function test_customer_export_includes_nothing_from_the_ghl_link(): void
    {
        $workspace = $this->workspace('dock.test');
        $tenantId = $workspace['tenant']->id;
        $customer = $this->customer($tenantId, 'Ada Kayak', 'ada@example.com');
        $booking = $this->booking($tenantId, $customer->id, 'ada-kayak');
        $token = 'pit-secret-ghl-token-value';
        GhlConnection::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => 'loc-ghl-private',
            'private_integration_token' => $token,
        ]);
        GhlSyncLog::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'kind' => 'booking.created',
            'attempt' => 1,
            'status' => 'succeeded',
            'location_id' => 'loc-ghl-private',
            'error' => 'pit-secret-ghl-token-value must stay out',
        ]);
        GhlMessageLog::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'kind' => 'booking.confirmed',
            'dedupe_key' => 'booking.confirmed:'.$booking->id,
            'status' => 'failed',
            'attempts' => 1,
            'error' => 'pit-secret-ghl-token-value must stay out',
            'location_id' => 'loc-ghl-private',
        ]);

        Storage::fake('local');
        $this->actingAsWorkspace($workspace);
        $created = $this->postJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$customer->id.'/exports'))
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'completed');
        $exportId = $created->json('data.id');
        $this->assertIsString($exportId);
        $path = $this->within($workspace['tenant'], function () use ($exportId): string {
            $path = CustomerDataExport::query()->findOrFail($exportId)->path;
            $this->assertIsString($path);

            return $path;
        });

        $entries = $this->zipEntries($path);
        $this->assertArrayNotHasKey('ghl_connections.json', $entries);
        $this->assertArrayNotHasKey('ghl_sync_logs.json', $entries);
        $this->assertArrayNotHasKey('ghl_message_logs.json', $entries);
        $blob = json_encode($entries, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($token, $blob);
        $this->assertStringNotContainsString('loc-ghl-private', $blob);
        $this->assertStringContainsString('ada@example.com', $blob);
    }

    private function member(Tenant $tenant, string $role): User
    {
        $user = User::factory()->create();
        Membership::factory()->role($role)->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
        ]);

        return $user;
    }

    private function customer(string $tenantId, string $name, string $email, ?string $phone = null): Customer
    {
        return Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function within(Tenant $tenant, callable $callback): mixed
    {
        app(TenantManager::class)->set($tenant);

        try {
            return $callback();
        } finally {
            app(TenantManager::class)->forget();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function zipEntries(string $relative): array
    {
        $archive = new ZipArchive;
        $opened = $archive->open(Storage::disk('local')->path($relative));
        $this->assertSame(true, $opened);

        $entries = [];

        for ($index = 0; $index < $archive->numFiles; $index++) {
            $name = $archive->getNameIndex($index);
            $contents = $name === false ? false : $archive->getFromIndex($index);
            $this->assertIsString($name);
            $this->assertIsString($contents);
            $entries[$name] = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        }

        $archive->close();

        return $entries;
    }

    /**
     * @return array{
     *     customer: Customer,
     *     neighbor: Customer,
     *     booking: Booking,
     *     payment: Payment,
     *     refund: Refund,
     *     fee: PlatformFee,
     *     operatorFee: BookingOperatorFee,
     *     participant: Participant,
     *     signature: WaiverSignature,
     *     waitlist: WaitlistEntry,
     *     neighborWaitlist: WaitlistEntry,
     *     incident: Incident,
     *     hook: NotificationHook,
     *     outbox: IntegrationOutbox,
     *     neighborOutbox: IntegrationOutbox,
     *     hash: string
     * }
     */
    private function graph(string $tenantId, string $actorId): array
    {
        $customer = $this->customer($tenantId, 'Ada Kayak', 'ada@example.com', '312-555-0100');
        $neighbor = $this->customer($tenantId, 'Bea Paddle', 'bea@example.com', '312-555-0199');
        $booking = $this->booking($tenantId, $customer->id, 'ada-kayak', 'Call Ada Kayak at 312-555-0100 or ada@example.com');
        $neighborBooking = $this->booking($tenantId, $neighbor->id, 'bea-paddle', 'Bea likes the blue boat');
        $payment = Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 10_300,
            'fee_cents' => 300,
            'stripe_payment_intent_id' => 'pi_ada',
        ]);
        Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $neighborBooking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 7777,
            'fee_cents' => 0,
            'stripe_payment_intent_id' => 'pi_bea',
        ]);
        $refund = Refund::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'reason' => 'customer_cancelled_partial_refund',
            'rules_version' => 'spec-1.0/7.7',
            'customer_refund_cents' => 400,
            'customer_fee_refund_cents' => 0,
            'application_fee_refund_cents' => 0,
            'refund_subtotal_cents' => 400,
            'refund_tax_cents' => 0,
            'actor_user_id' => $actorId,
        ]);
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => 300,
            'refunded_cents' => 0,
            'stripe_fee_id' => 'fee_ada',
            'channel' => 'direct',
        ]);
        $catalog = OperatorFee::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Cleaning',
            'pricing' => OperatorFeePricing::Flat,
            'amount_cents' => 500,
            'active' => true,
        ]);
        $operatorFee = BookingOperatorFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'operator_fee_id' => $catalog->id,
            'name' => 'Cleaning',
            'scope' => OperatorFeeScope::Service,
            'pricing' => OperatorFeePricing::Flat,
            'amount_cents' => 500,
            'quantity' => 1,
            'total_cents' => 500,
        ]);
        BookingReminder::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'offset_minutes' => 1440,
        ]);
        $participant = Participant::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'name' => 'Ada Kayak',
            'is_minor' => true,
            'guardian_name' => 'Sam Kayak',
            'payload_hash' => hash('sha256', 'ada-kayak-participant'),
        ]);
        $hash = hash('sha256', 'liability-waiver');
        $version = WaiverVersion::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Liability',
            'version' => 1,
            'body' => 'liability-waiver',
            'content_hash' => $hash,
        ]);
        $signature = WaiverSignature::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'participant_id' => $participant->id,
            'waiver_version_id' => $version->id,
            'participant_name' => 'Ada Kayak',
            'is_minor' => true,
            'guardian_name' => 'Sam Kayak',
            'ip_address' => '198.51.100.10',
            'user_agent' => 'GuestBrowser/1.0',
            'content_hash' => $hash,
            'signed_at' => '2026-06-01 12:00:00',
        ]);
        $waitlist = WaitlistEntry::query()->create([
            'tenant_id' => $tenantId,
            'service_id' => $booking->service_id,
            'location_id' => $booking->location_id,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 1,
            'customer_name' => 'Ada Kayak',
            'customer_email' => 'Ada@Example.com',
            'status' => WaitlistStatus::Waiting,
        ]);
        $neighborWaitlist = WaitlistEntry::query()->create([
            'tenant_id' => $tenantId,
            'service_id' => $neighborBooking->service_id,
            'location_id' => $neighborBooking->location_id,
            'booking_id' => $neighborBooking->id,
            'starts_at' => '2026-06-17 15:00:00',
            'ends_at' => '2026-06-17 16:00:00',
            'participants' => 1,
            'customer_name' => 'Bea Paddle',
            'customer_email' => 'bea@example.com',
            'status' => WaitlistStatus::Waiting,
        ]);
        $incident = Incident::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'summary' => 'Ada Kayak called from ada@example.com and 312-555-0100',
            'notes' => 'Ada Kayak called from ada@example.com and 312-555-0100',
            'occurred_at' => '2026-06-16 15:30:00',
        ]);
        $hook = NotificationHook::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'kind' => 'booking.confirmed',
            'payload' => ['email' => 'ada@example.com'],
            'delivery_error' => 'could not reach ada@example.com',
        ]);
        $outbox = IntegrationOutbox::query()->create([
            'tenant_id' => $tenantId,
            'destination' => 'ghl',
            'kind' => 'booking.confirmed',
            'payload' => ['booking_id' => $booking->id, 'email' => 'ada@example.com'],
            'last_error' => 'Ada Kayak mailbox failed',
        ]);
        $neighborOutbox = IntegrationOutbox::query()->create([
            'tenant_id' => $tenantId,
            'destination' => 'ghl',
            'kind' => 'booking.confirmed',
            'payload' => ['booking_id' => $neighborBooking->id, 'email' => 'bea@example.com'],
            'last_error' => 'Bea Paddle mailbox failed',
        ]);
        AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorId,
            'action' => 'participant.added',
            'subject_type' => Participant::class,
            'subject_id' => $participant->id,
            'properties' => ['name' => 'Ada Kayak', 'email' => 'ada@example.com'],
            'ip_address' => '203.0.113.10',
            'user_agent' => 'StaffBrowser/1.0',
            'created_at' => now(),
        ]);

        return [
            'customer' => $customer,
            'neighbor' => $neighbor,
            'booking' => $booking,
            'payment' => $payment,
            'refund' => $refund,
            'fee' => $fee,
            'operatorFee' => $operatorFee,
            'participant' => $participant,
            'signature' => $signature,
            'waitlist' => $waitlist,
            'neighborWaitlist' => $neighborWaitlist,
            'incident' => $incident,
            'hook' => $hook,
            'outbox' => $outbox,
            'neighborOutbox' => $neighborOutbox,
            'hash' => $hash,
        ];
    }

    private function booking(string $tenantId, string $customerId, string $name, ?string $notes = null): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customerId,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerId.$name),
            'staff_notes' => $notes,
        ]);
    }
}
