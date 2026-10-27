<?php

namespace Tests\Feature\Widget;

use App\Models\Asset;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use App\Support\BrandPalette;
use App\Support\CustomerChrome;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CustomerBookingAccessibilityTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        URL::forceRootUrl(null);
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_catalog_names_each_service_and_meets_the_contrast_floor(): void
    {
        [$workspace, $service] = $this->rental('dock.test');
        $token = $this->token($workspace);

        $catalog = $this->get($this->tenantUrl('dock.test', '/book/'.$token));

        $catalog->assertOk();
        $catalog->assertSee('Skip to booking');
        $catalog->assertSee('Check times for '.$service->name, false);
        $catalog->assertSee('href="#booking"', false);
        $catalog->assertSee('id="booking"', false);
        $catalog->assertSee('outline: 3px solid var(--brand-text)', false);
        $catalog->assertSee('--muted:'.CustomerChrome::MUTED, false);
        $catalog->assertSee('--line-strong:'.CustomerChrome::LINE_STRONG, false);
        $catalog->assertDontSee('--muted: #6B7280', false);
        $catalog->assertDontSee('--muted:#6B7280', false);
        $catalog->assertDontSee('outline: 3px solid var(--brand);', false);
        $catalog->assertDontSee('outline: 3px solid var(--brand-soft)', false);

        $html = (string) $catalog->getContent();
        $skip = strpos($html, 'href="#booking"');
        $main = strpos($html, 'id="booking"');
        $this->assertNotFalse($skip);
        $this->assertNotFalse($main);
        $this->assertLessThan($main, $skip);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 390px\) \{(?:[^{}]|\{[^}]*\})*\.card p:last-child a \{[^}]*display: block/s',
            $html,
        );
        $this->assertStringContainsString('overflow-wrap: anywhere', $html);
    }

    public function test_a_pale_brand_color_still_paints_a_dark_focus_ring(): void
    {
        [$workspace] = $this->rental('dock.test');
        TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update([
                'display_name' => 'Pale Dock',
                'primary_color' => '#FDE68A',
                'accent_color' => '#FDE68A',
            ]);
        $token = $this->token($workspace);

        $catalog = $this->get($this->tenantUrl('dock.test', '/book/'.$token));

        $catalog->assertOk()->assertSee('Pale Dock');
        $html = (string) $catalog->getContent();
        $this->assertSame(1, preg_match('/--brand-text:(#[0-9A-F]{6})/', $html, $matches));
        $this->assertNotSame('#FDE68A', $matches[1]);
        $this->assertGreaterThanOrEqual(4.5, BrandPalette::contrast($matches[1], CustomerChrome::SURFACE));
        $this->assertGreaterThanOrEqual(3.0, BrandPalette::contrast($matches[1], CustomerChrome::PAGE));
        $this->assertStringContainsString('outline: 3px solid var(--brand-text)', $html);
    }

    /**
     * @param  array{tenant: Tenant, domain: Domain, user: User, membership: Membership}  $workspace
     */
    private function token(array $workspace): string
    {
        $this->actingAsWorkspace($workspace);

        return (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service];
    }
}
