<?php

namespace Tests\Feature\Platform;

use App\Domain\Payments\StripeGateway;
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
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class FeeReconciliationTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_balanced_window_matches_stripe_and_ignores_fees_outside_it(): void
    {
        $start = CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC')->getTimestamp();
        $end = CarbonImmutable::parse('2026-06-03 00:00:00', 'UTC')->getTimestamp();
        $workspace = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Dock', 'slug' => 'alpha-dock']);
        $fake = new FakeStripeGateway;
        $fake->applicationFees = [
            $this->stripeFee('fee_in_a', 300, 100, 'acct_alpha', $start + 10),
            $this->stripeFee('fee_in_b', 200, 0, 'acct_alpha', $end - 10),
            $this->stripeFee('fee_before', 999, 0, 'acct_alpha', $start - 5),
        ];
        $this->app->instance(StripeGateway::class, $fake);

        $first = $this->fee($workspace['tenant'], 'fee_in_a', 300, 100, '2026-06-01 00:00:00');
        $second = $this->fee($workspace['tenant'], 'fee_in_b', 200, 0, '2026-06-02 23:59:59');
        $this->fee($workspace['tenant'], 'fee_after', 50, 0, '2026-06-03 00:00:00');

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->getJson('http://localhost/api/v1/platform/fee-reconciliation?from=2026-06-01&to=2026-06-02')
            ->assertOk()
            ->assertJsonPath('data.from', '2026-06-01')
            ->assertJsonPath('data.to', '2026-06-02')
            ->assertJsonPath('data.stored_gross_cents', 500)
            ->assertJsonPath('data.stored_refunded_cents', 100)
            ->assertJsonPath('data.stored_net_cents', 400)
            ->assertJsonPath('data.stripe_gross_cents', 500)
            ->assertJsonPath('data.stripe_refunded_cents', 100)
            ->assertJsonPath('data.stripe_net_cents', 400)
            ->assertJsonPath('data.balanced', true)
            ->assertJsonPath('data.differences', []);

        $this->assertSame([['gte' => $start, 'lt' => $end]], $fake->feeLists);
        $this->assertNotSame($first->id, $second->id);

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_reconciliation_viewed')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertNull($log->tenant_id);
        $this->assertTrue($log->properties['balanced'] ?? null);
        $this->assertSame(0, $log->properties['difference_count'] ?? null);
    }

    public function test_differences_list_a_missing_fee_a_stripe_only_fee_and_an_amount_mismatch(): void
    {
        $start = CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC')->getTimestamp();
        $alpha = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Dock', 'slug' => 'alpha-dock']);
        $bravo = $this->workspace('bravo.test', tenantAttributes: ['name' => 'Bravo Dock', 'slug' => 'bravo-dock']);
        StripeAccount::query()->create([
            'tenant_id' => $bravo['tenant']->id,
            'stripe_account_id' => 'acct_bravo',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);

        $fake = new FakeStripeGateway;
        $fake->applicationFees = [
            $this->stripeFee('fee_off', 300, 40, 'acct_alpha', $start + 20),
            $this->stripeFee('fee_new', 80, 0, 'acct_bravo', $start + 30),
            $this->stripeFee('fee_orphan', 15, 0, 'acct_unknown', $start + 40),
        ];
        $this->app->instance(StripeGateway::class, $fake);

        $unrecorded = $this->fee($alpha['tenant'], null, 40, 0, '2026-06-01 01:00:00');
        $missing = $this->fee($alpha['tenant'], 'fee_gone', 50, 0, '2026-06-01 02:00:00');
        $mismatch = $this->fee($alpha['tenant'], 'fee_off', 300, 100, '2026-06-01 03:00:00');

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $report = $this->getJson('http://localhost/api/v1/platform/fee-reconciliation?from=2026-06-01&to=2026-06-01')
            ->assertOk()
            ->assertJsonPath('data.balanced', false)
            ->assertJsonPath('data.stored_gross_cents', 390)
            ->assertJsonPath('data.stored_refunded_cents', 100)
            ->assertJsonPath('data.stripe_gross_cents', 395)
            ->assertJsonPath('data.stripe_refunded_cents', 40);

        $differences = $report->json('data.differences');
        $this->assertSame([
            [
                'kind' => 'amount_mismatch',
                'stripe_fee_id' => 'fee_off',
                'platform_fee_id' => $mismatch->id,
                'tenant_id' => $alpha['tenant']->id,
                'stored_amount_cents' => 300,
                'stored_refunded_cents' => 100,
                'stripe_amount_cents' => 300,
                'stripe_refunded_cents' => 40,
            ],
            [
                'kind' => 'missing_locally',
                'stripe_fee_id' => 'fee_new',
                'platform_fee_id' => null,
                'tenant_id' => $bravo['tenant']->id,
                'stored_amount_cents' => null,
                'stored_refunded_cents' => null,
                'stripe_amount_cents' => 80,
                'stripe_refunded_cents' => 0,
            ],
            [
                'kind' => 'missing_locally',
                'stripe_fee_id' => 'fee_orphan',
                'platform_fee_id' => null,
                'tenant_id' => null,
                'stored_amount_cents' => null,
                'stored_refunded_cents' => null,
                'stripe_amount_cents' => 15,
                'stripe_refunded_cents' => 0,
            ],
            [
                'kind' => 'missing_on_stripe',
                'stripe_fee_id' => null,
                'platform_fee_id' => $unrecorded->id,
                'tenant_id' => $alpha['tenant']->id,
                'stored_amount_cents' => 40,
                'stored_refunded_cents' => 0,
                'stripe_amount_cents' => null,
                'stripe_refunded_cents' => null,
            ],
            [
                'kind' => 'missing_on_stripe',
                'stripe_fee_id' => 'fee_gone',
                'platform_fee_id' => $missing->id,
                'tenant_id' => $alpha['tenant']->id,
                'stored_amount_cents' => 50,
                'stored_refunded_cents' => 0,
                'stripe_amount_cents' => null,
                'stripe_refunded_cents' => null,
            ],
        ], $differences);

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_reconciliation_viewed')
            ->first();
        $this->assertNotNull($log);
        $this->assertFalse($log->properties['balanced'] ?? null);
        $this->assertSame(5, $log->properties['difference_count'] ?? null);
    }

    public function test_reconciliation_is_refused_without_stripe(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->getJson('http://localhost/api/v1/platform/fee-reconciliation?from=2026-06-01&to=2026-06-02')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Stripe is not configured. Add the platform secret before taking online payments.');

        $this->assertNull(AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_reconciliation_viewed')
            ->first());
    }

    public function test_a_bad_window_is_refused_before_stripe_and_a_cut_off_list_is_not_balanced(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->getJson('http://localhost/api/v1/platform/fee-reconciliation?from=2026-06-02&to=2026-06-01')
            ->assertStatus(422)
            ->assertJsonPath('message', 'The end date must be on or after the start date.');
        $this->getJson('http://localhost/api/v1/platform/fee-reconciliation?from=June&to=2026-06-01')
            ->assertStatus(422);
        $this->getJson('http://localhost/api/v1/platform/fee-reconciliation?to=2026-06-01')
            ->assertStatus(422);
        $this->assertSame([], $fake->feeLists);

        $fake->truncateFeeList = true;
        $this->getJson('http://localhost/api/v1/platform/fee-reconciliation?from=2026-06-01&to=2026-06-01')
            ->assertStatus(409)
            ->assertJsonPath('message', 'This period has more Stripe fees than one reconciliation can read.');

        $this->assertNull(AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_reconciliation_viewed')
            ->first());
    }

    /**
     * @return array{id: string, amount: int, amount_refunded: int, account: ?string, created: int}
     */
    private function stripeFee(string $id, int $amount, int $refunded, ?string $account, int $created): array
    {
        return [
            'id' => $id,
            'amount' => $amount,
            'amount_refunded' => $refunded,
            'account' => $account,
            'created' => $created,
        ];
    }

    private function fee(Tenant $tenant, ?string $stripeFeeId, int $amount, int $refunded, string $createdAt): PlatformFee
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
            'payload_hash' => hash('sha256', $tenant->id.$createdAt.($stripeFeeId ?? 'none')),
        ]);
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'amount_cents' => $amount,
            'refunded_cents' => $refunded,
            'stripe_fee_id' => $stripeFeeId,
            'channel' => 'direct',
        ]);
        $fee->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $fee;
    }
}
