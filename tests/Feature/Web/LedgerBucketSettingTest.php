<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Settings;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\CapacityLedger;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Allocation\BucketPlanner;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class LedgerBucketSettingTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    public function test_an_owner_saves_the_block_length_and_a_pool_hold_uses_it(): void
    {
        $owner = $this->signedIn('alpha.test');
        $beta = $this->workspace('beta.test');
        $shop = $this->rentalShop($owner['tenant'], 4, 'UTC');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();
        $before = $this->moneyColumns($setting);
        $this->assertSame(15, $setting->ledger_bucket_minutes);

        $this->useTenant($owner['tenant']);
        $kept = CapacityLedger::query()->create([
            'tenant_id' => $owner['tenant']->id,
            'pool_id' => $shop['pool']->id,
            'bucket_start_utc' => '2020-01-01 00:05:00',
            'allocated_units' => 3,
            'held_units' => 2,
        ]);

        $this->get($this->tenantUrl('alpha.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('id="capacity-ledger"', false)
            ->assertSee('Shared gear lock')
            ->assertSee('max-width: 390px', false)
            ->assertSee('5 minutes')
            ->assertSee('60 minutes')
            ->assertSee('Save block length');

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->assertSet('ledgerBucketMinutes', 15)
            ->set('ledgerBucketMinutes', 60)
            ->call('saveLedgerBucket')
            ->assertHasNoErrors()
            ->assertSee('Shared-gear lock saved.');

        $setting->refresh();
        $kept->refresh();
        $this->assertSame(60, $setting->ledger_bucket_minutes);
        $this->assertSame($before, $this->moneyColumns($setting));
        $this->assertSame(3, $kept->allocated_units);
        $this->assertSame(2, $kept->held_units);
        $betaSetting = TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $beta['tenant']->id)->firstOrFail();
        $this->assertSame(15, $betaSetting->ledger_bucket_minutes);

        $audit = AuditLog::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertSame(15, $audit->properties['before']['ledger_bucket_minutes'] ?? null);
        $this->assertSame(60, $audit->properties['after']['ledger_bucket_minutes'] ?? null);
        $this->assertSame($owner['user']->id, $audit->actor_user_id);
        $this->assertMoneyUntouched();

        $manager = $this->signedIn('manager.test', 'manager');
        $this->useTenant($manager['tenant']);
        Livewire::test(Settings::class)
            ->set('ledgerBucketMinutes', 30)
            ->call('saveLedgerBucket')
            ->assertHasNoErrors();
        $this->assertSame(30, TenantSetting::query()->where('tenant_id', $manager['tenant']->id)->firstOrFail()->ledger_bucket_minutes);
        $this->assertSame(60, TenantSetting::withoutGlobalScope(TenantScope::class)->where('tenant_id', $owner['tenant']->id)->value('ledger_bucket_minutes'));

        $this->actingAsWorkspace($owner);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings'), [
            'service_id' => $shop['service']->id,
            'starts_at' => '2026-06-15T15:00:00Z',
            'ends_at' => '2026-06-15T16:00:00Z',
            'participants' => 1,
            'idempotency_key' => 'ledger-60',
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
        ])->assertCreated();

        $this->useTenant($owner['tenant']);
        $kept->refresh();
        $this->assertSame(3, $kept->allocated_units);
        $this->assertSame(2, $kept->held_units);
        $start = CarbonImmutable::parse('2026-06-15T15:00:00Z');
        $end = CarbonImmutable::parse('2026-06-15T16:00:00Z');
        $expected = app(BucketPlanner::class)->covering($start, $end, 60);
        $fifteen = app(BucketPlanner::class)->covering($start, $end, 15);
        $this->assertNotSame($fifteen, $expected);
        $stored = CapacityLedger::query()
            ->where('pool_id', $shop['pool']->id)
            ->where('id', '!=', $kept->id)
            ->orderBy('bucket_start_utc')
            ->get();
        $this->assertCount(count($expected), $stored);
        $this->assertSame($expected, $stored->map(
            fn (CapacityLedger $row): int => CarbonImmutable::parse($row->bucket_start_utc)->utc()->getTimestamp(),
        )->all());
        $setting->refresh();
        $this->assertSame($before, $this->moneyColumns($setting));
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_length_outside_the_list_is_refused(): void
    {
        $owner = $this->signedIn('alpha.test');
        $setting = TenantSetting::query()->where('tenant_id', $owner['tenant']->id)->firstOrFail();

        $this->useTenant($owner['tenant']);
        Livewire::test(Settings::class)
            ->set('ledgerBucketMinutes', 12)
            ->call('saveLedgerBucket')
            ->assertHasErrors(['ledgerBucketMinutes']);

        $setting->refresh();
        $this->assertSame(15, $setting->ledger_bucket_minutes);
        $this->assertSame(0, AuditLog::query()->where('action', 'settings.updated')->count());
        $this->assertMoneyUntouched();
    }

    public function test_staff_can_see_the_length_and_cannot_change_it(): void
    {
        $staff = $this->signedIn('staff.test', 'staff');
        $setting = TenantSetting::query()->where('tenant_id', $staff['tenant']->id)->firstOrFail();

        $this->get($this->tenantUrl('staff.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Shared gear lock')
            ->assertSee('15 minutes')
            ->assertDontSee('Save block length');

        $this->useTenant($staff['tenant']);
        Livewire::test(Settings::class)
            ->set('ledgerBucketMinutes', 5)
            ->call('saveLedgerBucket')
            ->assertForbidden();

        $setting->refresh();
        $this->assertSame(15, $setting->ledger_bucket_minutes);
        $this->assertSame(0, AuditLog::query()->where('action', 'settings.updated')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function moneyColumns(TenantSetting $setting): array
    {
        return $setting->only([
            'fee_payer',
            'hold_minutes',
            'direct_fee_bps',
            'third_party_fee_bps',
            'pay_at_pickup',
            'timezone',
            'email_payment_receipt',
            'late_fee_per_minute_cents',
            'late_grace_minutes',
            'guest_cancel_before_minutes',
        ]);
    }

    private function assertMoneyUntouched(): void
    {
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, LocationTaxRule::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Booking::withoutGlobalScope(TenantScope::class)->count());
    }

    /**
     * @return array{tenant: Tenant, user: User}
     */
    private function signedIn(string $host, string $role = 'owner'): array
    {
        $workspace = $this->workspace($host, $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
