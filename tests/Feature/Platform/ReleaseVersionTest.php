<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform\Readiness;
use App\Livewire\Platform\Upgrade;
use App\Models\AuditLog;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformAdministrator;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\User;
use App\Support\SchedulerHeartbeat;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ReleaseVersionTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    private const CANARY = 'not-semver-canary';

    private mixed $previousVersion = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousVersion = config('app.version');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        config(['app.version' => $this->previousVersion]);

        parent::tearDown();
    }

    public function test_a_semantic_version_names_its_release_tag_and_a_bad_version_is_not_ready(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 16:00:00', 'UTC'));
        $this->readyConfig();
        app(SchedulerHeartbeat::class)->beat();
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $before = $this->counts();

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('release=pass')
            ->expectsOutputToContain('ready=yes')
            ->doesntExpectOutputToContain('0.0.0')
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('This build is 0.0.0. The release tag is v0.0.0.')
            ->assertSee('data-check="release" data-state="pass"', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false);

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertOk()
            ->assertSee('Name the release')
            ->assertSee('This build is 0.0.0. The release tag is v0.0.0. A version that is not major.minor.patch fails readiness and is not printed. This page does not create the tag.')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false);

        Livewire::actingAs($admin)
            ->test(Upgrade::class)
            ->assertSee('The release tag is v0.0.0.');

        $deploy = (string) file_get_contents(base_path('docs/DEPLOY.md'));
        $runbook = (string) file_get_contents(base_path('docs/RUNBOOK.md'));
        $this->assertStringContainsString('This build is 0.0.0. The release tag is v0.0.0.', $deploy);
        $this->assertStringContainsString('This page does not create the tag.', $deploy);
        $this->assertStringContainsString('This build is 0.0.0. The release tag is v0.0.0.', $runbook);

        config(['app.version' => '1.2.3']);

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('release=pass')
            ->expectsOutputToContain('ready=yes')
            ->assertSuccessful();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertOk()
            ->assertSee('This build is 1.2.3. The release tag is v1.2.3.')
            ->assertDontSee(self::CANARY);

        foreach (['01.2.3', '1.2.3-rc.1', self::CANARY] as $rejected) {
            config(['app.version' => $rejected]);

            $this->actingAs($admin)
                ->get('http://app.reserveworx.com/platform/readiness')
                ->assertOk()
                ->assertSee('The release version is not semantic versioning.')
                ->assertDontSee($rejected);
        }

        config(['app.version' => self::CANARY]);
        $audits = AuditLog::query()->count();

        $this->artisan('reserveworx:readiness')
            ->expectsOutputToContain('release=fail')
            ->expectsOutputToContain('ready=no')
            ->doesntExpectOutputToContain(self::CANARY)
            ->assertFailed();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertOk()
            ->assertSee('Not ready')
            ->assertSee('The release version is not semantic versioning.')
            ->assertDontSee(self::CANARY);

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertOk()
            ->assertSee('The release version is not semantic versioning.')
            ->assertDontSee(self::CANARY);

        Livewire::actingAs($admin)
            ->test(Readiness::class)
            ->assertSee('The release version is not semantic versioning.')
            ->assertDontSee(self::CANARY);

        $this->assertSame($audits, AuditLog::query()->count());
        $this->assertSame($before, $this->counts());

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/readiness')
            ->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($admin)
            ->get($this->tenantUrl('alpha.test', '/platform/upgrade'))
            ->assertNotFound();

        config(['app.version' => '0.0.0']);

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertOk()
            ->assertDontSee('The release tag is v0.0.0.');
    }

    private function readyConfig(): void
    {
        config([
            'app.debug' => false,
            'app.env' => 'testing',
            'app.cipher' => 'AES-256-CBC',
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'hashing.driver' => 'bcrypt',
            'mail.default' => 'array',
            'queue.default' => 'database',
            'queue.connections.database.table' => 'jobs',
        ]);
    }

    /**
     * @return array{audits: int, payments: int, refunds: int, fees: int, fee_refunds: int, snapshots: int, prices: int, taxes: int}
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
            'prices' => PriceRule::query()->count(),
            'taxes' => LocationTaxRule::query()->count(),
        ];
    }
}
