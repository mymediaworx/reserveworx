<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Setup\Resources;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Operations\AssetRetirement;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffRetirementOfferLocationTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_retirement_offers_name_each_start_in_the_location_zone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->place($workspace['tenant'], 'America/New_York', 'Harbor');
        $utc = $this->place($workspace['tenant'], 'UTC', 'Utc');
        $fallback = $this->place($workspace['tenant'], 'Not/AZone', 'Fallback');
        $creek = $this->place($other['tenant'], 'America/New_York', 'Creek');

        $this->actingAsWorkspace($workspace);
        $earlyFall = $this->confirmed($harbor['service'], '2026-11-01T05:30:00Z', '2026-11-01T06:30:00Z', 'early-fall', 'Early Fall', 'early-fall@example.com');
        $lateFall = $this->confirmed($harbor['service'], '2026-11-01T06:30:00Z', '2026-11-01T07:30:00Z', 'late-fall', 'Late Fall', 'late-fall@example.com');
        $springEarly = $this->confirmed($harbor['service'], '2026-03-08T06:30:00Z', '2026-03-08T07:30:00Z', 'spring-early', 'Spring Early', 'spring-early@example.com');
        $springLate = $this->confirmed($harbor['service'], '2026-03-08T07:30:00Z', '2026-03-08T08:30:00Z', 'spring-late', 'Spring Late', 'spring-late@example.com');
        $utcVisit = $this->confirmed($utc['service'], '2026-06-16T15:00:00Z', '2026-06-16T16:00:00Z', 'utc-visit', 'Utc Guest', 'utc@example.com');
        $fallbackVisit = $this->confirmed($fallback['service'], '2026-07-04T20:00:00Z', '2026-07-04T21:00:00Z', 'fallback-visit', 'Fallback Guest', 'fallback@example.com');

        $this->actingAsWorkspace($other);
        $creekVisit = $this->confirmed($creek['service'], '2026-11-01T05:30:00Z', '2026-11-01T06:30:00Z', 'creek-visit', 'Creek Only', 'creek@example.com', 'creek.test');

        $this->retire($workspace['tenant'], $harbor['asset'], $harbor['replacement']);
        $this->retire($workspace['tenant'], $utc['asset'], $utc['replacement']);
        $this->retire($workspace['tenant'], $fallback['asset'], $fallback['replacement']);
        $this->retire($other['tenant'], $creek['asset'], $creek['replacement']);

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $feeRefunds = $this->rows(PlatformFeeRefund::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $audits = $this->rows(AuditLog::class);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(Resources::class)
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('Sun, Mar 8, 1:30 AM EST')
            ->assertSee('Sun, Mar 8, 3:30 AM EDT')
            ->assertSee('Tue, Jun 16, 3:00 PM UTC')
            ->assertSee('Sat, Jul 4, 3:00 PM CDT')
            ->assertSee('Early Fall')
            ->assertSee('Late Fall')
            ->assertSee('Utc Guest')
            ->assertSee('Fallback Guest')
            ->assertSeeHtml('data-retire-offer="'.$earlyFall.'"')
            ->assertSeeHtml('data-retire-offer="'.$lateFall.'"')
            ->assertSeeHtml('data-retire-offer="'.$springEarly.'"')
            ->assertSeeHtml('data-retire-offer="'.$springLate.'"')
            ->assertSeeHtml('data-retire-offer="'.$utcVisit.'"')
            ->assertSeeHtml('data-retire-offer="'.$fallbackVisit.'"')
            ->assertSeeHtml('data-when')
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.resources-page .retire-offers .when { overflow-wrap: anywhere; max-width: 100%; }', false)
            ->assertDontSee('Creek Only')
            ->assertDontSee('creek@example.com')
            ->assertDontSeeHtml('data-retire-offer="'.$creekVisit.'"')
            ->assertDontSee('Not/AZone');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($feeRefunds, $this->rows(PlatformFeeRefund::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $this->assertSame('2026-11-01 05:30:00', $this->storedStart($earlyFall));
        $this->assertSame('2026-11-01 06:30:00', $this->storedStart($lateFall));
        $this->assertSame('2026-03-08 06:30:00', $this->storedStart($springEarly));
        $this->assertSame('2026-03-08 07:30:00', $this->storedStart($springLate));
        $this->assertSame('2026-06-16 15:00:00', $this->storedStart($utcVisit));
        $this->assertSame('2026-07-04 20:00:00', $this->storedStart($fallbackVisit));
        $this->assertSame('2026-11-01 05:30:00', $this->storedStart($creekVisit));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    /**
     * @return array{location: Location, asset: Asset, replacement: Asset, service: Service}
     */
    private function place(Tenant $tenant, string $timezone, string $name): array
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => $timezone,
            'name' => $name.' Dock',
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name.' boats',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name.' Boat',
        ]);
        $replacement = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name.' Replacement',
        ]);
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name.' rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [
            'location' => $location,
            'asset' => $asset,
            'replacement' => $replacement,
            'service' => $service,
        ];
    }

    private function confirmed(Service $service, string $start, string $end, string $key, string $customer, string $email, string $host = 'dock.test'): string
    {
        $bookingId = $this->postJson($this->tenantUrl($host, '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => $start,
            'ends_at' => $end,
            'participants' => 1,
            'customer_name' => $customer,
            'customer_email' => $email,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->assertIsString($bookingId);
        $this->postJson($this->tenantUrl($host, '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    private function retire(Tenant $tenant, Asset $asset, Asset $replacement): void
    {
        app(TenantManager::class)->set($tenant);
        app(AssetRetirement::class)->retire($asset, $replacement->id);
        app(TenantManager::class)->forget();
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }

    private function storedStart(string $bookingId): string
    {
        return Booking::withoutGlobalScope(TenantScope::class)->findOrFail($bookingId)->starts_at->utc()->format('Y-m-d H:i:s');
    }
}
