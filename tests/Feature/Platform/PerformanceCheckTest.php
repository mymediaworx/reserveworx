<?php

namespace Tests\Feature\Platform;

use App\Enums\TenantStatus;
use App\Livewire\Platform\Performance;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\User;
use App\Models\WidgetToken;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PerformanceCheckTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    private string $memory = '128M';

    private const KEY_CANARY = 'base64:UGVyZm9ybWFuY2VDaGVja0NhbmFyeUtleTEyMzQ1Njc=';

    protected function setUp(): void
    {
        parent::setUp();

        $current = ini_get('memory_limit');
        $this->memory = is_string($current) ? $current : '128M';
        ini_set('memory_limit', '256M');
        config(['app.key' => self::KEY_CANARY]);
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        ini_set('memory_limit', $this->memory);
        parent::tearDown();
    }

    public function test_a_loaded_workspace_stays_within_the_query_budget_and_writes_nothing(): void
    {
        $alpha = $this->workspace('a-shop.test', 'owner', ['name' => 'Alpha Kayak Co']);
        $beta = $this->workspace('b-shop.test', 'owner', ['name' => 'Beta Secret Co']);
        $this->rentalShop($alpha['tenant']);
        $secret = $this->rentalShop($beta['tenant']);
        Service::query()->withoutGlobalScope(TenantScope::class)->whereKey($secret['service']->id)->update([
            'name' => 'Secret tandem',
        ]);
        $admin = $this->admin();
        $before = $this->counts();

        $exit = Artisan::call('reserveworx:performance');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('memory_limit=pass', $output);
        $this->assertStringContainsString('memory_limit_value=256M', $output);
        $this->assertStringContainsString('product=pass', $output);
        $this->assertStringContainsString('product_error=none', $output);
        $this->assertStringContainsString('signup=pass', $output);
        $this->assertStringContainsString('signup_error=none', $output);
        $this->assertStringContainsString('booking=pass', $output);
        $this->assertStringContainsString('booking_host=a-shop.test', $output);
        $this->assertStringContainsString('booking_services=1', $output);
        $this->assertStringContainsString('booking_error=none', $output);
        $this->assertStringContainsString('public_internet=note', $output);
        $this->assertStringContainsString('within_budget=yes', $output);
        $this->assertMatchesRegularExpression('/product_queries=\d+/', $output);
        $this->assertMatchesRegularExpression('/product_ms=\d+/', $output);
        $this->assertMatchesRegularExpression('/booking_queries=\d+/', $output);
        $this->assertMatchesRegularExpression('/booking_ms=\d+/', $output);
        $this->assertStringNotContainsString('b-shop.test', $output);
        $this->assertStringNotContainsString('http://', $output);
        $this->assertStringNotContainsString('https://', $output);
        $this->assertStringNotContainsString('hosted_', $output);
        $this->assertStringNotContainsString(self::KEY_CANARY, $output);
        $this->assertStringNotContainsString('Alpha Kayak Co', $output);
        $this->assertStringNotContainsString('Beta Secret Co', $output);
        $this->assertStringNotContainsString('Secret tandem', $output);
        $this->assertSame($before, $this->counts());
        Http::assertNothingSent();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/performance')
            ->assertOk()
            ->assertSee('Performance')
            ->assertSee('Within budget')
            ->assertSee('data-within="yes"', false)
            ->assertSee('memory_limit is 256M, which is at least 128M.')
            ->assertSee('The product page rendered with')
            ->assertSee('Signup rendered with')
            ->assertSee('The booking page for a-shop.test rendered with')
            ->assertSee('for 1 services.')
            ->assertSee('This process did not call the public internet.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('/platform/performance', false)
            ->assertDontSee('b-shop.test')
            ->assertDontSee('Alpha Kayak Co')
            ->assertDontSee('Beta Secret Co')
            ->assertDontSee('Kayak rental')
            ->assertDontSee('Secret tandem')
            ->assertDontSee('hosted_')
            ->assertDontSee(self::KEY_CANARY);

        Livewire::actingAs($admin)->test(Performance::class)
            ->assertSee('Within budget')
            ->assertDontSee('Secret tandem');

        $this->assertSame($before, $this->counts());
        Http::assertNothingSent();
    }

    public function test_a_suspended_workspace_is_not_measured(): void
    {
        $workspace = $this->workspace('suspended-only.test', 'owner', [
            'name' => 'Suspended Secret Co',
            'status' => TenantStatus::Suspended,
        ]);
        $this->rentalShop($workspace['tenant']);
        $before = $this->counts();

        $exit = Artisan::call('reserveworx:performance');
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('booking=note', $output);
        $this->assertStringContainsString('booking_host=none', $output);
        $this->assertStringContainsString('within_budget=yes', $output);
        $this->assertStringNotContainsString('suspended-only.test', $output);
        $this->assertStringNotContainsString('Suspended Secret Co', $output);
        $this->assertSame($before, $this->counts());

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/performance')
            ->assertOk()
            ->assertSee('No verified workspace is loaded, so the booking page was not measured.')
            ->assertDontSee('suspended-only.test')
            ->assertDontSee('Suspended Secret Co');
    }

    public function test_a_missing_platform_host_is_over_budget(): void
    {
        config(['reserveworx.platform_domains' => []]);

        $exit = Artisan::call('reserveworx:performance');
        $output = Artisan::output();

        $this->assertSame(1, $exit, $output);
        $this->assertStringContainsString('product=fail', $output);
        $this->assertStringContainsString('signup=fail', $output);
        $this->assertStringContainsString('within_budget=no', $output);
        $this->assertStringNotContainsString(self::KEY_CANARY, $output);

        Livewire::actingAs($this->admin())->test(Performance::class)
            ->assertSee('Over budget')
            ->assertSee('data-within="no"', false)
            ->assertSee('The platform host is not configured, so the product page was not measured.');
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_check(): void
    {
        $this->get('http://app.reserveworx.com/platform/performance')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/performance')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(Performance::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($this->admin())
            ->get($this->tenantUrl('alpha.test', '/platform/performance'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/performance'))
            ->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        return $admin;
    }

    /**
     * @return array{audits: int, payments: int, refunds: int, fees: int, fee_refunds: int, snapshots: int, rules: int, taxes: int, tokens: int, bookings: int}
     */
    private function counts(): array
    {
        return [
            'audits' => AuditLog::query()->count(),
            'payments' => Payment::query()->count(),
            'refunds' => Refund::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'fee_refunds' => PlatformFeeRefund::query()->count(),
            'snapshots' => PricingSnapshot::query()->count(),
            'rules' => PriceRule::query()->withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::query()->withoutGlobalScope(TenantScope::class)->count(),
            'tokens' => WidgetToken::query()->withoutGlobalScope(TenantScope::class)->count(),
            'bookings' => Booking::query()->withoutGlobalScope(TenantScope::class)->count(),
        ];
    }
}
