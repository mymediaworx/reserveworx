<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform\Limits;
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
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class SharedHostLimitPageTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const KEY_CANARY = 'base64:TGltaXRzUGFnZUNhbmFyeUtleURvTm90U2hvdzEyMzQ=';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => self::KEY_CANARY]);
    }

    public function test_a_passing_server_shows_the_window_and_writes_nothing(): void
    {
        $admin = $this->admin();
        Tenant::factory()->create(['name' => 'Secret Harbor Limits']);
        $before = $this->counts();

        $this->artisan('reserveworx:limits')
            ->expectsOutput('window_seconds=20')
            ->expectsOutput('configured_queue_work_max_time=20')
            ->expectsOutput('queue_worker=bounded')
            ->expectsOutput('jobs_within_window=yes')
            ->expectsOutput('schedule_overlap=yes')
            ->expectsOutput('within_limit=yes')
            ->doesntExpectOutputToContain(self::KEY_CANARY)
            ->doesntExpectOutputToContain('LimitsPageCanaryKeyDoNotShow1234')
            ->doesntExpectOutputToContain('Secret Harbor Limits')
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/limits')
            ->assertOk()
            ->assertSee('Limits')
            ->assertSee('Within the limit')
            ->assertSee('The shared-host window is 20 seconds.')
            ->assertSee('The configured queue work max time is 20, which is inside 10 to 20 seconds.')
            ->assertSee('The queue worker stops when empty and uses a max time inside 10 to 20 seconds.')
            ->assertSee('Every queued job timeout is from 1 second through that window.')
            ->assertSee('Every scheduled task refuses to overlap.')
            ->assertSee('data-within="yes"', false)
            ->assertSee('data-window="20"', false)
            ->assertSee('data-configured="20"', false)
            ->assertSee('data-worker="bounded"', false)
            ->assertSee('data-jobs="yes"', false)
            ->assertSee('data-overlap="yes"', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('href="/platform/limits"', false)
            ->assertDontSee(self::KEY_CANARY)
            ->assertDontSee('LimitsPageCanaryKeyDoNotShow1234')
            ->assertDontSee('Secret Harbor Limits')
            ->assertDontSee('job_timeout');

        Livewire::actingAs($admin)->test(Limits::class)
            ->assertSee('Within the limit')
            ->assertDontSee(self::KEY_CANARY)
            ->assertDontSee('LimitsPageCanaryKeyDoNotShow1234')
            ->assertDontSee('Secret Harbor Limits');

        $this->assertSame($before, $this->counts());
    }

    public function test_a_configured_time_outside_the_window_stays_on_the_page(): void
    {
        config(['reserveworx.queue_work_max_time' => 90]);
        $before = $this->counts();

        $this->artisan('reserveworx:limits')
            ->expectsOutput('window_seconds=20')
            ->expectsOutput('configured_queue_work_max_time=90')
            ->expectsOutput('queue_worker=bounded')
            ->expectsOutput('jobs_within_window=yes')
            ->expectsOutput('schedule_overlap=yes')
            ->expectsOutput('within_limit=no')
            ->doesntExpectOutputToContain('job_timeout=')
            ->doesntExpectOutputToContain(self::KEY_CANARY)
            ->doesntExpectOutputToContain('LimitsPageCanaryKeyDoNotShow1234')
            ->assertFailed();

        $this->actingAs($this->admin())
            ->get('http://app.reserveworx.com/platform/limits')
            ->assertOk()
            ->assertSee('Outside the limit')
            ->assertSee('The configured queue work max time is 90, which is outside 10 to 20 seconds.')
            ->assertSee('data-within="no"', false)
            ->assertSee('data-window="20"', false)
            ->assertSee('data-configured="90"', false)
            ->assertSee('data-state="fail"', false)
            ->assertDontSee(self::KEY_CANARY)
            ->assertDontSee('LimitsPageCanaryKeyDoNotShow1234');

        $this->assertSame($before, $this->counts());
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_check(): void
    {
        $this->get('http://app.reserveworx.com/platform/limits')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/limits')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(Limits::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($this->admin())
            ->get($this->tenantUrl('alpha.test', '/platform/limits'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/limits'))
            ->assertNotFound();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        return $admin;
    }

    /**
     * @return array{audits: int, payments: int, refunds: int, fees: int, fee_refunds: int, snapshots: int, rules: int, taxes: int, bookings: int}
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
            'bookings' => Booking::query()->withoutGlobalScope(TenantScope::class)->count(),
        ];
    }
}
