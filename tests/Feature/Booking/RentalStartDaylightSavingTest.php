<?php

namespace Tests\Feature\Booking;

use App\Livewire\Staff\NewBooking;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

/**
 * Rental starts keep both fall-back hours and skip the missing spring-forward hour.
 */
class RentalStartDaylightSavingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_fall_back_morning_names_both_hours_and_holds_the_later_one(): void
    {
        $this->freeze('2026-11-01 04:00:00');
        $shop = $this->nightShop('America/New_York');
        $this->otherRental('America/New_York');
        $money = $this->moneyCounts();
        $page = $this->times($shop, '2026-11-01');

        $page->assertOk()
            ->assertSee('Times are in America/New_York.')
            ->assertSee('1:30 AM EDT')
            ->assertSee('1:30 AM EST')
            ->assertSee('value="01:30-04:00"', false)
            ->assertSee('value="01:30-05:00"', false)
            ->assertSee('value="00:30"', false)
            ->assertSee('12:30 AM')
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Secret paddle')
            ->assertDontSee('value="01:30"', false);
        $html = (string) $page->getContent();
        $this->assertSame(1, preg_match('/<input type="radio" name="start" value="01:30-04:00"(?![^>]*disabled)/', $html));
        $this->assertSame(1, preg_match('/<input type="radio" name="start" value="01:30-05:00"(?![^>]*disabled)/', $html));

        app(TenantManager::class)->set($shop['tenant']);
        $this->actingAs($shop['user']);
        Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->assertSet('date', '2026-11-01')
            ->assertSee('Times are in America/New_York.')
            ->assertSee('1:30 AM EDT')
            ->assertSee('1:30 AM EST')
            ->assertSee('2026-11-01T05:30:00+00:00', false)
            ->assertSee('2026-11-01T06:30:00+00:00', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertDontSee('Secret paddle');
        app(TenantManager::class)->forget();

        $this->assertSame($money, $this->moneyCounts());

        $held = $this->post($this->bookUrl($shop), $this->party('later', '2026-11-01', '01:30-05:00'));
        $held->assertRedirect();
        app(TenantManager::class)->set($shop['tenant']);
        $booking = Booking::query()->firstOrFail();
        $this->assertSame('2026-11-01 06:30:00', $booking->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 07:30:00', $booking->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('held', $booking->status->value);
        $this->assertSame($money['payments'], Payment::query()->count());
        $this->assertSame($money['refunds'], Refund::query()->count());
        $this->assertSame($money['fees'], PlatformFee::query()->count());
        $this->assertSame($money['fee_refunds'], PlatformFeeRefund::query()->count());
        $this->assertSame($money['prices'], PriceRule::query()->count());
        $this->assertSame($money['taxes'], LocationTaxRule::query()->count());
        $this->assertSame($money['snapshots'] + 1, PricingSnapshot::query()->count());
    }

    public function test_a_plain_clock_on_a_fall_back_morning_holds_the_earlier_hour(): void
    {
        $this->freeze('2026-11-01 04:00:00');
        $shop = $this->nightShop('America/New_York');
        $money = $this->moneyCounts();

        $this->post($this->bookUrl($shop), $this->party('earlier', '2026-11-01', '01:30'))
            ->assertRedirect();

        app(TenantManager::class)->set($shop['tenant']);
        $booking = Booking::query()->firstOrFail();
        $this->assertSame('2026-11-01 05:30:00', $booking->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        app(TenantManager::class)->forget();

        $this->post($this->bookUrl($shop), $this->party('wrong-offset', '2026-11-01', '01:30-03:00'))
            ->assertStatus(422)
            ->assertSee('Choose a valid date and time.');

        app(TenantManager::class)->set($shop['tenant']);
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame('2026-11-01 05:30:00', Booking::query()->firstOrFail()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame($money['prices'], PriceRule::query()->count());
        $this->assertSame($money['taxes'], LocationTaxRule::query()->count());
        $this->assertSame($money['snapshots'] + 1, PricingSnapshot::query()->count());
    }

    public function test_a_spring_forward_morning_leaves_out_the_missing_hour(): void
    {
        $this->freeze('2026-03-08 05:00:00');
        $shop = $this->nightShop('America/New_York');
        $money = $this->moneyCounts();
        $html = (string) $this->times($shop, '2026-03-08')->assertOk()->getContent();

        $this->assertStringContainsString('Times are in America/New_York.', $html);
        $this->assertStringContainsString('>1:30 AM<', $html);
        $this->assertStringContainsString('>3:00 AM<', $html);
        $this->assertStringContainsString('value="01:30"', $html);
        $this->assertStringContainsString('value="03:00"', $html);
        $this->assertStringNotContainsString('value="02:00"', $html);
        $this->assertStringNotContainsString('value="02:30"', $html);
        $this->assertStringNotContainsString('>2:00 AM<', $html);
        $this->assertStringNotContainsString('>2:30 AM<', $html);

        app(TenantManager::class)->set($shop['tenant']);
        $this->actingAs($shop['user']);
        $staff = Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->assertSet('date', '2026-03-08')
            ->assertSee('1:30 AM')
            ->assertSee('3:00 AM')
            ->html();
        $this->assertStringNotContainsString('>2:00 AM<', $staff);
        $this->assertStringNotContainsString('>2:30 AM<', $staff);
        app(TenantManager::class)->forget();

        $this->assertSame($money, $this->moneyCounts());

        $this->post($this->bookUrl($shop), $this->party('missing', '2026-03-08', '02:30'))
            ->assertStatus(422)
            ->assertSee('Choose a valid date and time.');

        app(TenantManager::class)->set($shop['tenant']);
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame($money['snapshots'], PricingSnapshot::query()->count());
        $this->assertSame($money['payments'], Payment::query()->count());
        $this->assertSame($money['refunds'], Refund::query()->count());
        $this->assertSame($money['fees'], PlatformFee::query()->count());
        $this->assertSame($money['fee_refunds'], PlatformFeeRefund::query()->count());
        $this->assertSame($money['prices'], PriceRule::query()->count());
        $this->assertSame($money['taxes'], LocationTaxRule::query()->count());
        $this->assertSame($money['audits'], AuditLog::query()->count());
    }

    public function test_an_invalid_location_zone_uses_the_business_zone(): void
    {
        $this->freeze('2026-11-01 05:00:00');
        $shop = $this->nightShop('Not/AZone', 'America/Chicago');
        $money = $this->moneyCounts();

        $this->times($shop, '2026-11-01')
            ->assertOk()
            ->assertSee('Times are in America/Chicago.')
            ->assertSee('1:30 AM CDT')
            ->assertSee('1:30 AM CST')
            ->assertSee('value="01:30-05:00"', false)
            ->assertSee('value="01:30-06:00"', false)
            ->assertDontSee('Not/AZone');

        app(TenantManager::class)->set($shop['tenant']);
        $this->actingAs($shop['user']);
        Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->assertSee('Times are in America/Chicago.')
            ->assertSee('1:30 AM CDT')
            ->assertSee('1:30 AM CST')
            ->assertDontSee('Not/AZone');

        $this->assertSame($money, $this->moneyCounts());
    }

    private function freeze(string $utc): void
    {
        $now = CarbonImmutable::parse($utc, 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership, service: Service}
     */
    private function nightShop(string $timezone, ?string $businessZone = null): array
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Dock']);
        app(TenantManager::class)->set($workspace['tenant']);

        if ($businessZone !== null) {
            TenantSetting::query()->where('tenant_id', $workspace['tenant']->id)->update(['timezone' => $businessZone]);
        }

        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => $timezone,
            'name' => 'Night dock',
        ]);

        foreach (range(0, 6) as $weekday) {
            AvailabilitySchedule::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'owner_type' => 'location',
                'owner_id' => $location->id,
                'weekday' => $weekday,
                'opens_local' => '00:00:00',
                'closes_local' => '06:00:00',
            ]);
        }

        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Night rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Night',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'always',
        ]);
        app(TenantManager::class)->forget();

        return [...$workspace, 'service' => $service];
    }

    private function otherRental(string $timezone): void
    {
        $other = $this->workspace('other.test', 'owner', ['name' => 'Other Dock']);
        app(TenantManager::class)->set($other['tenant']);
        $location = Location::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'timezone' => $timezone,
            'name' => 'Other dock',
        ]);
        Service::query()->create([
            'tenant_id' => $other['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Secret paddle',
            'kind' => 'rental',
        ]);
        app(TenantManager::class)->forget();
    }

    /**
     * @param  array{tenant: Tenant, service: Service}  $shop
     */
    private function times(array $shop, string $date): TestResponse
    {
        $token = $this->token($shop);

        return $this->get($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$shop['service']->id.'?date='.$date.'&duration_minutes=60&participants=1'));
    }

    /**
     * @param  array{tenant: Tenant, service: Service}  $shop
     */
    private function bookUrl(array $shop): string
    {
        return $this->tenantUrl('dock.test', '/book/'.$this->token($shop).'/services/'.$shop['service']->id);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $shop
     */
    private function token(array $shop): string
    {
        $this->actingAsWorkspace($shop);

        return (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
    }

    /**
     * @return array<string, string|int>
     */
    private function party(string $key, string $date, string $start): array
    {
        return [
            'date' => $date,
            'start' => $start,
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => $key,
        ];
    }

    /**
     * @return array{prices: int, payments: int, fees: int, fee_refunds: int, refunds: int, taxes: int, snapshots: int, audits: int}
     */
    private function moneyCounts(): array
    {
        return [
            'prices' => PriceRule::query()->withoutGlobalScope(TenantScope::class)->count(),
            'payments' => Payment::query()->withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::query()->withoutGlobalScope(TenantScope::class)->count(),
            'fee_refunds' => PlatformFeeRefund::query()->withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::query()->withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::query()->withoutGlobalScope(TenantScope::class)->count(),
            'snapshots' => PricingSnapshot::query()->withoutGlobalScope(TenantScope::class)->count(),
            'audits' => AuditLog::query()->withoutGlobalScope(TenantScope::class)->count(),
        ];
    }
}
