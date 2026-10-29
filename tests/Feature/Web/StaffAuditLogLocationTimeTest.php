<?php

namespace Tests\Feature\Web;

use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsZoneAbbreviations;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffAuditLogLocationTimeTest extends TestCase
{
    use AssertsZoneAbbreviations, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_audit_log_names_both_fall_back_hours_and_keeps_utc(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Harbor dock',
            'timezone' => 'America/New_York',
            'created_at' => CarbonImmutable::parse('2026-01-01 12:00:00', 'UTC'),
        ]);
        Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Later dock',
            'timezone' => 'America/Los_Angeles',
            'created_at' => CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC'),
        ]);
        Location::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'name' => 'Creek dock',
            'timezone' => 'America/New_York',
        ]);

        $this->row($workspace['tenant']->id, [
            'action' => 'booking.cancelled',
            'properties' => ['reason' => 'First fall hour'],
            'created_at' => CarbonImmutable::parse('2026-11-01 05:30:00', 'UTC'),
        ]);
        $this->row($workspace['tenant']->id, [
            'action' => 'booking.cancelled',
            'properties' => ['reason' => 'Second fall hour'],
            'created_at' => CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC'),
        ]);
        $this->row($other['tenant']->id, [
            'action' => 'booking.cancelled',
            'properties' => ['reason' => 'Creek only note'],
            'created_at' => CarbonImmutable::parse('2026-11-01 05:30:00', 'UTC'),
        ]);

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $fallPage = (string) $this->get($this->tenantUrl('dock.test', '/staff/audit'))
            ->assertOk()
            ->assertSee('Audit log')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('First fall hour')
            ->assertSee('Second fall hour')
            ->assertSee('.audit-page .audit-when { overflow-wrap: anywhere; max-width: 100%; }', false)
            ->assertDontSee('Creek only note')
            ->assertDontSee('America/Chicago')
            ->assertDontSee('America/Los_Angeles')
            ->assertDontSee('PDT')
            ->getContent();
        $this->assertSeeInOrder($fallPage, [
            'Sun, Nov 1, 1:30 AM EST',
            'Sun, Nov 1, 1:30 AM EDT',
        ]);

        Location::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->delete();
        Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Utc dock',
            'timezone' => 'UTC',
        ]);
        $this->row($workspace['tenant']->id, [
            'action' => 'settings.updated',
            'properties' => ['reason' => 'Utc note'],
            'created_at' => CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'),
        ]);

        $utcPage = $this->get($this->tenantUrl('dock.test', '/staff/audit'))
            ->assertOk()
            ->assertSee('Utc note')
            ->assertSee('Tue, Jun 16, 6:00 PM UTC')
            ->assertDontSee('Creek only note');
        $this->assertOmitsZoneAbbreviations($utcPage, 'EDT', 'CDT');

        Location::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->delete();
        Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Broken dock',
            'timezone' => 'Not/AZone',
        ]);
        $this->row($workspace['tenant']->id, [
            'action' => 'settings.updated',
            'properties' => ['reason' => 'Fallback note'],
            'created_at' => CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC'),
        ]);

        $this->get($this->tenantUrl('dock.test', '/staff/audit'))
            ->assertOk()
            ->assertSee('Fallback note')
            ->assertSee('Tue, Jun 16, 1:00 PM CDT')
            ->assertDontSee('Not/AZone')
            ->assertDontSee('1:00 PM EDT')
            ->assertDontSee('Creek only note');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits + 2, $this->rows(AuditLog::class));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function row(string $tenantId, array $attributes): AuditLog
    {
        return AuditLog::withoutGlobalScope(TenantScope::class)->create(array_merge([
            'tenant_id' => $tenantId,
            'actor_user_id' => null,
            'action' => 'booking.updated',
            'subject_type' => null,
            'subject_id' => null,
            'properties' => [],
            'ip_address' => null,
            'user_agent' => null,
            'created_at' => CarbonImmutable::now('UTC'),
        ], $attributes));
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertSeeInOrder(string $html, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $found = strpos($html, $needle, $offset);
            $this->assertNotFalse($found, $needle.' was not found after offset '.$offset);
            $offset = $found + strlen($needle);
        }
    }
}
