<?php

namespace Tests\Feature\Web;

use App\Enums\ServiceKind;
use App\Enums\WaitlistStatus;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\Display;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

/**
 * Staff waitlist dates follow each location's local day, and the clock names that zone.
 */
class StaffWaitlistLocationDayTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_fall_back_day_keeps_the_last_hour_and_names_both_one_thirties(): void
    {
        $workspace = $this->signedIn();
        $service = $this->service($workspace['tenant']->id, 'America/New_York', 'Harbor tour');
        $early = $this->entry($service, '2026-11-01 05:30:00', '2026-11-01 05:45:00', 'Early hour', 'UTC');
        $later = $this->entry($service, '2026-11-01 06:30:00', '2026-11-01 06:45:00', 'Later hour', 'UTC');
        $late = $this->entry($service, '2026-11-01 23:30:00', '2026-11-01 23:45:00', 'Last hour');
        $nextMorning = $this->entry($service, '2026-11-02 00:30:00', '2026-11-02 00:45:00', 'Next morning');
        $other = $this->otherWorkspaceEntry('2026-11-01 23:30:00', 'Secret paddle');
        $money = $this->moneyCounts();

        $page = $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-11-01'))
            ->assertOk()
            ->assertSee('Each time uses that location’s clock.')
            ->assertSee('data-entry="'.$early->id.'"', false)
            ->assertSee('data-entry="'.$later->id.'"', false)
            ->assertSee('data-entry="'.$late->id.'"', false)
            ->assertSee(Display::rangeWithZone($early->starts_at, $early->ends_at, 'America/New_York'), false)
            ->assertSee(Display::rangeWithZone($later->starts_at, $later->ends_at, 'America/New_York'), false)
            ->assertSee(Display::rangeWithZone($late->starts_at, $late->ends_at, 'America/New_York'), false)
            ->assertSee('1:30 AM – 1:45 AM EDT', false)
            ->assertSee('1:30 AM – 1:45 AM EST', false)
            ->assertSee('11:30 PM – 11:45 PM EST', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Next morning')
            ->assertDontSee($nextMorning->id, false)
            ->assertDontSee('Secret paddle')
            ->assertDontSee($other->id, false);

        $this->assertStringContainsString('Sun, Nov 1, 1:30 AM – 1:45 AM EDT', (string) $page->getContent());
        $this->assertStringContainsString('Sun, Nov 1, 1:30 AM – 1:45 AM EST', (string) $page->getContent());
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_a_spring_forward_day_leaves_out_the_next_morning_and_names_the_span(): void
    {
        $workspace = $this->signedIn();
        $service = $this->service($workspace['tenant']->id, 'America/New_York', 'Dawn tour');
        $crossing = $this->entry($service, '2026-03-08 01:30:00', '2026-03-08 04:30:00', 'Crossing hour');
        $nextMorning = $this->entry($service, '2026-03-09 00:30:00', '2026-03-09 00:45:00', 'Next morning');
        $label = Display::rangeWithZone($crossing->starts_at, $crossing->ends_at, 'America/New_York');
        $money = $this->moneyCounts();

        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-03-08'))
            ->assertOk()
            ->assertSee($label, false)
            ->assertSee('1:30 AM EST', false)
            ->assertSee('4:30 AM EDT', false)
            ->assertSee('Crossing hour')
            ->assertDontSee('Next morning')
            ->assertDontSee($nextMorning->id, false);

        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-03-09'))
            ->assertOk()
            ->assertSee('Next morning')
            ->assertDontSee('Crossing hour');

        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_utc_stays_utc_and_an_invalid_zone_uses_the_business_zone(): void
    {
        $workspace = $this->signedIn();
        $this->businessZone($workspace['tenant']->id, 'America/Chicago');
        $utc = $this->service($workspace['tenant']->id, 'UTC', 'Utc tour');
        $late = $this->entry($utc, '2026-06-15 23:30:00', '2026-06-15 23:45:00', 'Utc late');
        $next = $this->entry($utc, '2026-06-16 00:30:00', '2026-06-16 00:45:00', 'Utc next');
        $invalid = $this->service($workspace['tenant']->id, 'Not/AZone', 'River tour');
        $chicago = $this->entry($invalid, '2026-06-15 10:00:00', '2026-06-15 11:00:00', 'Chicago hour', 'America/Chicago');
        $money = $this->moneyCounts();

        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-06-15'))
            ->assertOk()
            ->assertSee(Display::rangeWithZone($late->starts_at, $late->ends_at, 'UTC'), false)
            ->assertSee('11:30 PM – 11:45 PM UTC', false)
            ->assertSee(Display::rangeWithZone($chicago->starts_at, $chicago->ends_at, 'America/Chicago'), false)
            ->assertSee('10:00 AM – 11:00 AM CDT', false)
            ->assertSee('Utc late')
            ->assertSee('Chicago hour')
            ->assertDontSee('Utc next')
            ->assertDontSee('Not/AZone');

        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_each_location_keeps_its_own_local_day(): void
    {
        $workspace = $this->signedIn();
        $losAngeles = $this->service($workspace['tenant']->id, 'America/Los_Angeles', 'Pacific tour');
        $newYork = $this->service($workspace['tenant']->id, 'America/New_York', 'Atlantic tour');
        $pacific = $this->entry($losAngeles, '2026-11-01 23:30:00', '2026-11-01 23:45:00', 'Pacific late');
        $atlantic = $this->entry($newYork, '2026-11-02 00:30:00', '2026-11-02 00:45:00', 'Atlantic next');
        $money = $this->moneyCounts();

        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-11-01'))
            ->assertOk()
            ->assertSee('Pacific late')
            ->assertSee(Display::rangeWithZone($pacific->starts_at, $pacific->ends_at, 'America/Los_Angeles'), false)
            ->assertDontSee('Atlantic next')
            ->assertDontSee($atlantic->id, false);

        $this->assertSame($money, $this->moneyCounts());
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(): array
    {
        $workspace = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function service(string $tenantId, string $timezone, string $name): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => $timezone,
            'name' => $name,
        ]);

        return Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Tour,
            'min_participants' => 1,
            'max_participants' => 8,
            'waitlist_enabled' => true,
        ]);
    }

    private function entry(
        Service $service,
        string $start,
        string $end,
        string $name,
        ?string $zone = null,
    ): WaitlistEntry {
        $zone ??= (string) $service->location?->timezone;

        return WaitlistEntry::query()->create([
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => CarbonImmutable::parse($start, $zone)->utc(),
            'ends_at' => CarbonImmutable::parse($end, $zone)->utc(),
            'participants' => 2,
            'customer_name' => $name,
            'customer_email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'status' => WaitlistStatus::Waiting,
        ]);
    }

    private function otherWorkspaceEntry(string $local, string $name): WaitlistEntry
    {
        $current = app(TenantManager::class)->get();
        $other = $this->workspace('other.test', 'owner', ['name' => 'Other Dock']);
        app(TenantManager::class)->set($other['tenant']);
        $service = $this->service($other['tenant']->id, 'America/New_York', 'Other tour');
        $entry = $this->entry($service, $local, '2026-11-01 23:45:00', $name);

        if ($current instanceof Tenant) {
            app(TenantManager::class)->set($current);
        }

        return $entry;
    }

    private function businessZone(string $tenantId, string $zone): void
    {
        TenantSetting::query()->where('tenant_id', $tenantId)->update(['timezone' => $zone]);
    }

    /**
     * @return array{prices: int, payments: int, fees: int, fee_refunds: int, refunds: int, taxes: int, audits: int}
     */
    private function moneyCounts(): array
    {
        return [
            'prices' => PriceRule::query()->count(),
            'payments' => Payment::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'fee_refunds' => PlatformFeeRefund::query()->count(),
            'refunds' => Refund::query()->count(),
            'taxes' => LocationTaxRule::query()->count(),
            'audits' => AuditLog::query()->count(),
        ];
    }
}
