<?php

namespace Tests\Feature\Platform;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Location;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class FeeRevenueTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_platform_administrator_reads_stored_fees_by_workspace_and_channel(): void
    {
        $alpha = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Dock', 'slug' => 'alpha-dock']);
        $bravo = $this->workspace('bravo.test', tenantAttributes: ['name' => 'Bravo Dock', 'slug' => 'bravo-dock']);

        $this->fee($alpha['tenant'], 'direct', 300, 100, '2026-06-01 12:00:00');
        $this->fee($alpha['tenant'], 'direct', 200, 0, '2026-06-02 08:00:00');
        $this->fee($alpha['tenant'], 'third_party', 50, 0, '2026-06-02 09:00:00');
        $this->fee($bravo['tenant'], 'direct', 400, 0, '2026-06-02 10:00:00');
        $this->fee($alpha['tenant'], 'direct', 999, 0, '2026-05-31 23:59:59');

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $report = $this->getJson('http://localhost/api/v1/platform/fee-revenue?from=2026-06-01&to=2026-06-02')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-06-01')
            ->assertJsonPath('data.to', '2026-06-02')
            ->assertJsonPath('data.gross_cents', 950)
            ->assertJsonPath('data.refunded_cents', 100)
            ->assertJsonPath('data.manual_refunded_cents', 0)
            ->assertJsonPath('data.net_cents', 850);

        $tenants = $report->json('data.tenants');
        $this->assertIsArray($tenants);
        $this->assertCount(2, $tenants);
        $this->assertSame('Alpha Dock', $tenants[0]['name']);
        $this->assertSame(550, $tenants[0]['gross_cents']);
        $this->assertSame(100, $tenants[0]['refunded_cents']);
        $this->assertSame(450, $tenants[0]['net_cents']);
        $this->assertSame('direct', $tenants[0]['channels'][0]['channel']);
        $this->assertSame(500, $tenants[0]['channels'][0]['gross_cents']);
        $this->assertSame(2, $tenants[0]['channels'][0]['count']);
        $this->assertSame('third_party', $tenants[0]['channels'][1]['channel']);
        $this->assertSame(50, $tenants[0]['channels'][1]['gross_cents']);
        $this->assertSame('Bravo Dock', $tenants[1]['name']);
        $this->assertSame(400, $tenants[1]['gross_cents']);

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_revenue_viewed')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertNull($log->tenant_id);
        $this->assertSame('2026-06-01', $log->properties['from'] ?? null);
        $this->assertSame('2026-06-02', $log->properties['to'] ?? null);

        $this->getJson('http://localhost/api/v1/platform/fee-revenue?from=2026-06-02&to=2026-06-01')
            ->assertStatus(422);
        $this->getJson('http://localhost/api/v1/platform/fee-revenue?from=June')
            ->assertStatus(422);
    }

    public function test_a_workspace_member_cannot_read_platform_fee_revenue(): void
    {
        $workspace = $this->workspace('alpha.test');
        $this->actingAsWorkspace($workspace);

        $this->getJson('http://localhost/api/v1/platform/fee-revenue')->assertForbidden();
    }

    private function fee(Tenant $tenant, string $channel, int $amount, int $refunded, string $createdAt): void
    {
        $location = Location::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Harbor',
            'timezone' => 'America/Chicago',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Kayak',
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-02 15:00:00',
            'ends_at' => '2026-06-02 16:00:00',
            'participants' => 1,
            'payload_hash' => hash('sha256', $tenant->id.$channel.$createdAt),
        ]);
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'amount_cents' => $amount,
            'refunded_cents' => $refunded,
            'channel' => $channel,
        ]);
        $fee->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();
    }
}
