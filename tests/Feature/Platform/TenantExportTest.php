<?php

namespace Tests\Feature\Platform;

use App\Enums\CustomerDataExportStatus;
use App\Enums\StaffUndoKind;
use App\Livewire\Platform\TenantDetail;
use App\Models\BookingListView;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\CustomerDataDeletion;
use App\Models\CustomerDataExport;
use App\Models\CustomerSignInToken;
use App\Models\FeedbackRequest;
use App\Models\FleetItem;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\LocationFleetReserve;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\ReportSummary;
use App\Models\StaffSearchToken;
use App\Models\StaffUndoAction;
use App\Models\TenantDataExport;
use App\Models\TenantSetupGuide;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TenantExportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_export_contains_only_that_workspace_and_unlocks_termination(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alpha['user']->forceFill(['email' => 'alpha-owner@example.com'])->save();
        $beta['user']->forceFill(['email' => 'beta-owner@example.com'])->save();
        Location::factory()->create(['tenant_id' => $alpha['tenant']->id, 'name' => 'Alpha Dock']);
        Location::factory()->create(['tenant_id' => $beta['tenant']->id, 'name' => 'Beta Dock']);
        Sanctum::actingAs($admin);

        $this->patchJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id, [
            'status' => 'terminated',
        ])->assertStatus(409);

        $created = $this->postJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id.'/exports')
            ->assertCreated();
        $id = $created->json('data.id');
        $this->assertIsString($id);

        $path = 'tenant-exports/'.$alpha['tenant']->id.'/'.$id.'.json';
        Storage::disk('local')->assertExists($path);
        $json = Storage::disk('local')->get($path);
        $this->assertIsString($json);
        $this->assertStringContainsString('Alpha Dock', $json);
        $this->assertStringNotContainsString('Beta Dock', $json);
        $this->assertStringContainsString('alpha-owner@example.com', $json);
        $this->assertStringNotContainsString('beta-owner@example.com', $json);
        $this->assertStringNotContainsString('"password"', $json);

        $this->get('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id.'/exports/'.$id)
            ->assertOk();

        $this->get('http://localhost/api/v1/platform/tenants/'.$beta['tenant']->id.'/exports/'.$id)
            ->assertNotFound();

        $this->patchJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id, [
            'status' => 'terminated',
        ])->assertOk()->assertJsonPath('data.status', 'terminated');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.data_exported',
            'tenant_id' => $alpha['tenant']->id,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_a_tenant_member_cannot_export_a_workspace(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->actingAsWorkspace($alpha);

        $this->postJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id.'/exports')
            ->assertForbidden();

        $this->assertSame(0, TenantDataExport::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_the_platform_page_exports_and_then_closes(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $alpha = $this->workspace('alpha.test');
        $this->actingAs($admin);

        Livewire::test(TenantDetail::class, ['tenant' => $alpha['tenant']->id])
            ->call('exportData')
            ->assertSet('problem', null)
            ->call('setStatus', 'terminated')
            ->assertSet('problem', null);

        $this->assertSame('terminated', $alpha['tenant']->fresh()?->status->value);

        $exportId = TenantDataExport::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->value('id');
        $this->assertIsString($exportId);

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/tenants/'.$alpha['tenant']->id.'/exports/'.$exportId)
            ->assertOk();
    }

    public function test_the_export_includes_later_workspace_records_and_leaves_credentials_out(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alphaDock = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Alpha Dock',
        ]);
        $betaDock = Location::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta Dock',
        ]);

        LocationClosure::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $alphaDock->id,
            'reason' => 'Alpha Independence Day',
        ]);
        LocationClosure::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaDock->id,
            'reason' => 'Beta only holiday',
        ]);

        $alphaFleet = FleetItem::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Alpha shared kayaks',
        ]);
        $betaFleet = FleetItem::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta shared canoes',
        ]);
        LocationFleetReserve::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $alphaDock->id,
            'fleet_item_id' => $alphaFleet->id,
            'quantity' => 3,
        ]);
        LocationFleetReserve::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaDock->id,
            'fleet_item_id' => $betaFleet->id,
            'quantity' => 9,
        ]);

        FeedbackRequest::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'comment' => 'Alpha smooth water',
        ]);
        FeedbackRequest::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'comment' => 'Beta choppy water',
        ]);

        $alphaEndpoint = WebhookEndpoint::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'url' => 'https://hooks.alpha.example/reserveworx',
            'secret' => 'whsec_alpha_export_secret',
            'events' => ['booking.created'],
            'active' => true,
        ]);
        $betaEndpoint = WebhookEndpoint::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'url' => 'https://hooks.beta.example/reserveworx',
            'secret' => 'whsec_beta_export_secret',
            'events' => ['booking.created'],
            'active' => true,
        ]);
        $alphaDelivery = WebhookDelivery::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'webhook_endpoint_id' => $alphaEndpoint->id,
            'event' => 'booking.created',
            'dedupe_key' => 'alpha-export-hook',
            'payload' => ['marker' => 'alpha-hook-payload'],
            'status' => 'succeeded',
            'attempt_count' => 1,
        ]);
        $betaDelivery = WebhookDelivery::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'webhook_endpoint_id' => $betaEndpoint->id,
            'event' => 'booking.created',
            'dedupe_key' => 'beta-export-hook',
            'payload' => ['marker' => 'beta-hook-payload'],
            'status' => 'succeeded',
            'attempt_count' => 1,
        ]);
        WebhookDeliveryAttempt::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'webhook_delivery_id' => $alphaDelivery->id,
            'attempt' => 1,
            'status' => 'succeeded',
            'response_code' => 200,
            'duration_ms' => 12,
            'response_body' => 'alpha-hook-response',
        ]);
        WebhookDeliveryAttempt::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'webhook_delivery_id' => $betaDelivery->id,
            'attempt' => 1,
            'status' => 'succeeded',
            'response_code' => 200,
            'duration_ms' => 12,
            'response_body' => 'beta-hook-response',
        ]);

        ReportSummary::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $alphaDock->id,
            'summary_date' => '2026-08-01',
            'available_resource_minutes' => 60,
            'allocated_resource_minutes' => 30,
            'bookings_created' => 1,
            'bookings_converted' => 1,
            'average_order_cents' => 1000,
            'gross_cents' => 424242,
        ]);
        ReportSummary::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaDock->id,
            'summary_date' => '2026-08-01',
            'available_resource_minutes' => 60,
            'allocated_resource_minutes' => 30,
            'bookings_created' => 1,
            'bookings_converted' => 1,
            'average_order_cents' => 1000,
            'gross_cents' => 515151,
        ]);

        BookingListView::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $alpha['user']->id,
            'name' => 'Alpha morning view',
            'filters' => ['status' => 'confirmed'],
        ]);
        BookingListView::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $beta['user']->id,
            'name' => 'Beta evening view',
            'filters' => ['status' => 'confirmed'],
        ]);
        TenantSetupGuide::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'hidden' => false,
            'step_states' => ['alpha_export_step' => 'done'],
        ]);
        TenantSetupGuide::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'hidden' => false,
            'step_states' => ['beta_export_step' => 'done'],
        ]);

        $customer = Customer::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Alpha Guest',
            'email' => 'alpha-guest@example.com',
        ]);
        $account = CustomerAccount::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'email' => 'alpha-guest@example.com',
            'name' => 'Alpha Guest',
            'password' => 'alpha-account-password-not-exported',
        ]);
        $passwordHash = $account->password;
        $this->assertIsString($passwordHash);
        $signInHash = hash('sha256', 'alpha-sign-in-plain-not-exported');
        CustomerSignInToken::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'customer_account_id' => $account->id,
            'token_hash' => $signInHash,
            'expires_at' => now()->addHour(),
        ]);
        StaffSearchToken::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'subject_type' => 'customer',
            'subject_id' => $customer->id,
            'token' => 'alpha-search-token-secret',
        ]);
        StaffUndoAction::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $alpha['user']->id,
            'kind' => StaffUndoKind::ClosureDelete,
            'payload' => ['marker' => 'alpha-undo-not-exported'],
            'message' => 'Alpha undo not exported',
            'expires_at' => now()->addMinute(),
        ]);
        $customerExport = CustomerDataExport::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'customer_id' => $customer->id,
            'actor_user_id' => $alpha['user']->id,
            'disk' => 'local',
            'path' => 'customer-exports/'.$alpha['tenant']->id.'/alpha-privacy.zip',
            'status' => CustomerDataExportStatus::Completed,
            'completed_at' => now(),
        ]);
        CustomerDataDeletion::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'customer_id' => $customer->id,
            'actor_user_id' => $alpha['user']->id,
            'export_id' => $customerExport->id,
            'confirmed' => false,
            'anonymized_at' => now(),
        ]);

        $money = [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'prices' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ];

        Sanctum::actingAs($admin);
        $created = $this->postJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id.'/exports')
            ->assertCreated();
        $id = $created->json('data.id');
        $this->assertIsString($id);

        $json = Storage::disk('local')->get('tenant-exports/'.$alpha['tenant']->id.'/'.$id.'.json');
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $tables = $decoded['tables'] ?? null;
        $this->assertIsArray($tables);

        $this->assertSame('Alpha Independence Day', $tables['closures'][0]['reason'] ?? null);
        $this->assertSame('Alpha shared kayaks', $tables['fleet_items'][0]['name'] ?? null);
        $this->assertSame(3, $tables['location_fleet_reserves'][0]['quantity'] ?? null);
        $this->assertSame('Alpha morning view', $tables['booking_list_views'][0]['name'] ?? null);
        $this->assertSame('done', $tables['tenant_setup_guides'][0]['step_states']['alpha_export_step'] ?? null);
        $this->assertSame('alpha-privacy.zip', basename((string) ($tables['customer_data_exports'][0]['path'] ?? '')));
        $this->assertSame($customer->id, $tables['customer_data_deletions'][0]['customer_id'] ?? null);
        $this->assertSame('Alpha smooth water', $tables['feedback_requests'][0]['comment'] ?? null);
        $this->assertSame(424242, $tables['report_summaries'][0]['gross_cents'] ?? null);
        $this->assertSame('https://hooks.alpha.example/reserveworx', $tables['webhook_endpoints'][0]['url'] ?? null);
        $this->assertArrayNotHasKey('secret', $tables['webhook_endpoints'][0]);
        $this->assertArrayNotHasKey('url_hash', $tables['webhook_endpoints'][0]);
        $this->assertSame('alpha-hook-payload', $tables['webhook_deliveries'][0]['payload']['marker'] ?? null);
        $this->assertSame('alpha-hook-response', $tables['webhook_delivery_attempts'][0]['response_body'] ?? null);
        $this->assertArrayNotHasKey('customer_sign_in_tokens', $tables);
        $this->assertArrayNotHasKey('staff_search_tokens', $tables);
        $this->assertArrayNotHasKey('staff_undo_actions', $tables);

        $this->assertStringNotContainsString('Beta only holiday', $json);
        $this->assertStringNotContainsString('Beta shared canoes', $json);
        $this->assertStringNotContainsString('Beta choppy water', $json);
        $this->assertStringNotContainsString('Beta evening view', $json);
        $this->assertStringNotContainsString('beta_export_step', $json);
        $this->assertStringNotContainsString('beta-hook-payload', $json);
        $this->assertStringNotContainsString('beta-hook-response', $json);
        $this->assertStringNotContainsString('whsec_alpha_export_secret', $json);
        $this->assertStringNotContainsString('whsec_beta_export_secret', $json);
        $this->assertStringNotContainsString('alpha-account-password-not-exported', $json);
        $this->assertStringNotContainsString($passwordHash, $json);
        $this->assertStringNotContainsString($signInHash, $json);
        $this->assertStringNotContainsString('alpha-sign-in-plain-not-exported', $json);
        $this->assertStringNotContainsString('alpha-search-token-secret', $json);
        $this->assertStringNotContainsString('alpha-undo-not-exported', $json);
        $this->assertStringNotContainsString('Alpha undo not exported', $json);
        $this->assertStringNotContainsString('"password"', $json);

        $this->assertSame($money, [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'prices' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ]);
    }
}
