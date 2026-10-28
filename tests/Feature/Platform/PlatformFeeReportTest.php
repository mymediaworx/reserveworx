<?php

namespace Tests\Feature\Platform;

use App\Domain\Payments\StripeGateway;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Platform\FeeReport;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PlatformFeeReportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_platform_admin_reads_stored_fees_without_calling_stripe_or_writing_money(): void
    {
        Carbon::setTestNow('2026-06-15 15:00:00');
        $alpha = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Dock', 'slug' => 'alpha-dock']);
        $bravo = $this->workspace('bravo.test', tenantAttributes: ['name' => 'Bravo Dock', 'slug' => 'bravo-dock']);
        $manualFee = $this->fee($alpha['tenant'], 'direct', 300, 100, '2026-06-01 12:00:00', 'fee_manual');
        PlatformFeeRefund::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'platform_fee_id' => $manualFee->id,
            'amount_cents' => 40,
            'reason' => 'Courtesy',
            'rule' => PlatformFeeRefund::RULE,
            'idempotency_key' => 'manual-fee-report',
        ]);
        $this->fee($alpha['tenant'], 'direct', 200, 0, '2026-06-02 08:00:00', 'fee_direct');
        $this->fee($alpha['tenant'], 'third_party', 50, 0, '2026-06-02 09:00:00', 'fee_third');
        $this->fee($bravo['tenant'], 'direct', 400, 0, '2026-06-02 10:00:00', 'fee_bravo');
        $this->fee($alpha['tenant'], 'direct', 999, 0, '2026-05-16 23:59:59', 'fee_before');
        $this->fee($alpha['tenant'], 'direct', 700, 0, '2026-06-16 00:00:00', 'fee_after');

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $before = $this->moneyCounts();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/fees')
            ->assertOk()
            ->assertSee('Booking fees')
            ->assertSee('does not call Stripe', false)
            ->assertSee('href="/platform/fees"', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('2026-05-17')
            ->assertSee('2026-06-15')
            ->assertSee('data-gross="950"', false)
            ->assertSee('data-refunded="100"', false)
            ->assertSee('data-manual="40"', false)
            ->assertSee('data-net="850"', false)
            ->assertSee('$9.50')
            ->assertSee('$1.00')
            ->assertSee('$0.40')
            ->assertSee('$8.50')
            ->assertSee('Manual refunds are already included in refunded.')
            ->assertSee('Alpha Dock')
            ->assertSee('Bravo Dock')
            ->assertSee('Direct')
            ->assertSee('Third party')
            ->assertSee('data-tenant="'.$alpha['tenant']->id.'"', false)
            ->assertSee('data-channel="direct"', false)
            ->assertSee('data-channel="third_party"', false)
            ->assertDontSee('$8.10')
            ->assertDontSee('$9.99')
            ->assertDontSee('$7.00');

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_revenue_viewed')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertNull($log->tenant_id);
        $this->assertSame('2026-05-17', $log->properties['from'] ?? null);
        $this->assertSame('2026-06-15', $log->properties['to'] ?? null);
        $this->assertSame([], $fake->feeLists);

        Livewire::actingAs($admin)
            ->test(FeeReport::class)
            ->assertSet('from', '2026-05-17')
            ->assertSet('to', '2026-06-15')
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-02')
            ->call('apply')
            ->assertSee('No booking fees were recorded in this period.')
            ->assertSee('data-gross="0"', false)
            ->set('from', '2026-06-02')
            ->set('to', '2026-06-01')
            ->call('apply')
            ->assertSee('The end date must be on or after the start date.')
            ->assertSet('appliedFrom', '2026-01-01')
            ->assertSet('appliedTo', '2026-01-02')
            ->assertSee('data-gross="0"', false)
            ->set('from', 'June')
            ->set('to', '2026-06-02')
            ->call('apply')
            ->assertSee('Use a start date and an end date.')
            ->assertSet('appliedFrom', '2026-01-01')
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-01')
            ->call('apply')
            ->assertSee('data-gross="300"', false)
            ->assertSee('data-refunded="100"', false)
            ->assertSee('data-manual="40"', false)
            ->assertSee('data-net="200"', false)
            ->assertSee('data-channel="direct"', false)
            ->assertDontSee('data-channel="third_party"', false)
            ->assertDontSee('Bravo Dock');

        $this->assertSame([], $fake->feeLists);
        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_reconciliation_viewed')
            ->count());
        $this->assertSame($before, $this->moneyCounts());
    }

    public function test_compare_uses_the_period_on_screen_and_does_not_write_money(): void
    {
        Carbon::setTestNow('2026-06-15 15:00:00');
        $workspace = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Dock', 'slug' => 'alpha-dock']);
        $start = CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC')->getTimestamp();
        $end = CarbonImmutable::parse('2026-06-03 00:00:00', 'UTC')->getTimestamp();
        $fake = new FakeStripeGateway;
        $fake->applicationFees = [
            $this->stripeFee('fee_in_a', 300, 100, 'acct_alpha', $start + 10),
            $this->stripeFee('fee_in_b', 200, 0, 'acct_alpha', $end - 10),
            $this->stripeFee('fee_before', 999, 0, 'acct_alpha', $start - 5),
        ];
        $this->app->instance(StripeGateway::class, $fake);
        $first = $this->fee($workspace['tenant'], 'direct', 300, 100, '2026-06-01 00:00:00', 'fee_in_a');
        $second = $this->fee($workspace['tenant'], 'direct', 200, 0, '2026-06-02 23:59:59', 'fee_in_b');
        $this->fee($workspace['tenant'], 'direct', 50, 0, '2026-06-03 00:00:00', 'fee_after');
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $before = $this->moneyCounts();

        Livewire::actingAs($admin)
            ->test(FeeReport::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-02')
            ->call('apply')
            ->set('from', '2026-01-01')
            ->set('to', '2026-01-01')
            ->call('compare')
            ->assertSet('comparison.balanced', true)
            ->assertSee('data-balanced="yes"', false)
            ->assertSee('This period matches Stripe.')
            ->assertDontSee('fee_in_a')
            ->assertDontSee('acct_alpha');

        $this->assertSame([['gte' => $start, 'lt' => $end]], $fake->feeLists);
        $this->assertSame(300, $first->refresh()->amount_cents);
        $this->assertSame(200, $second->refresh()->amount_cents);
        $this->assertSame($before, $this->moneyCounts());

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_reconciliation_viewed')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertNull($log->tenant_id);
        $this->assertTrue($log->properties['balanced'] ?? null);
        $this->assertSame(0, $log->properties['difference_count'] ?? null);
        $this->assertSame('2026-06-01', $log->properties['from'] ?? null);
        $this->assertSame('2026-06-02', $log->properties['to'] ?? null);
    }

    public function test_a_stripe_difference_is_labeled_without_the_fee_id(): void
    {
        Carbon::setTestNow('2026-06-15 15:00:00');
        $workspace = $this->workspace('alpha.test', tenantAttributes: ['name' => 'Alpha Dock', 'slug' => 'alpha-dock']);
        $start = CarbonImmutable::parse('2026-06-01 00:00:00', 'UTC')->getTimestamp();
        $fake = new FakeStripeGateway;
        $fake->applicationFees = [
            $this->stripeFee('fee_only_on_stripe', 80, 0, 'acct_alpha', $start + 30),
        ];
        $this->app->instance(StripeGateway::class, $fake);
        $this->fee($workspace['tenant'], 'direct', 40, 0, '2026-06-01 01:00:00', null);
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(FeeReport::class)
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-01')
            ->call('apply')
            ->call('compare')
            ->assertSet('comparison.balanced', false)
            ->assertSee('data-balanced="no"', false)
            ->assertSee('data-kind="missing_locally"', false)
            ->assertSee('data-kind="missing_on_stripe"', false)
            ->assertSee('On Stripe, missing here')
            ->assertSee('Stored here, missing on Stripe')
            ->assertSee('Alpha Dock')
            ->assertDontSee('fee_only_on_stripe');
    }

    public function test_stripe_errors_and_a_bad_window_do_not_record_a_reconciliation(): void
    {
        Carbon::setTestNow('2026-06-15 15:00:00');
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        Livewire::actingAs($admin)
            ->test(FeeReport::class)
            ->call('compare')
            ->assertSee('Stripe is not configured. Add the platform secret before taking online payments.')
            ->assertSet('comparison', null);

        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_reconciliation_viewed')
            ->count());

        $fake = new FakeStripeGateway;
        $fake->truncateFeeList = true;
        $this->app->instance(StripeGateway::class, $fake);

        Livewire::actingAs($admin)
            ->test(FeeReport::class)
            ->call('compare')
            ->assertSee('This period has more Stripe fees than one reconciliation can read.')
            ->assertSet('comparison', null);

        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'platform.fee_reconciliation_viewed')
            ->count());

        $calls = count($fake->feeLists);

        Livewire::actingAs($admin)
            ->test(FeeReport::class)
            ->set('from', '2026-06-02')
            ->set('to', '2026-06-01')
            ->call('apply')
            ->assertSee('The end date must be on or after the start date.')
            ->assertSet('appliedFrom', '2026-05-17')
            ->assertSet('appliedTo', '2026-06-15');

        $this->assertCount($calls, $fake->feeLists);
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_page(): void
    {
        $this->get('http://app.reserveworx.com/platform/fees')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/fees')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(FeeReport::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get($this->tenantUrl('alpha.test', '/platform/fees'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/fees'))
            ->assertNotFound();
    }

    /**
     * @return array{payments: int, refunds: int, fees: int, fee_amount: int, fee_refunded: int, fee_refunds: int, prices: int, taxes: int}
     */
    private function moneyCounts(): array
    {
        return [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'fee_amount' => (int) PlatformFee::withoutGlobalScope(TenantScope::class)->sum('amount_cents'),
            'fee_refunded' => (int) PlatformFee::withoutGlobalScope(TenantScope::class)->sum('refunded_cents'),
            'fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'prices' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ];
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

    private function fee(Tenant $tenant, string $channel, int $amount, int $refunded, string $createdAt, ?string $stripeFeeId): PlatformFee
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
            'payload_hash' => hash('sha256', $tenant->id.$channel.$createdAt.($stripeFeeId ?? 'none')),
        ]);
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'amount_cents' => $amount,
            'refunded_cents' => $refunded,
            'channel' => $channel,
            'stripe_fee_id' => $stripeFeeId,
        ]);
        $fee->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $fee;
    }
}
