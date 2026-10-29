<?php

namespace Tests\Feature\Platform;

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
use App\Support\UpgradeGuide;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class FirstInstallGuideTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_platform_admin_can_read_the_first_install_and_nothing_is_written(): void
    {
        $install = app(UpgradeGuide::class)->install();

        $this->get('http://app.reserveworx.com/platform/upgrade')
            ->assertRedirect('/platform/login');

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $before = $this->counts();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertOk()
            ->assertSee($install['title'])
            ->assertSee($install['body'])
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertDontSee('3%')
            ->assertDontSee('sk_live')
            ->assertDontSee('whsec_');

        $this->assertSame($before, $this->counts());

        $deploy = (string) file_get_contents(base_path('docs/DEPLOY.md'));
        $runbook = (string) file_get_contents(base_path('docs/RUNBOOK.md'));
        $this->assertStringContainsString('### '.$install['title'], $deploy);
        $this->assertStringContainsString($install['body'], $deploy);
        $this->assertStringContainsString($install['body'], $runbook);
        $this->assertStringContainsString('/platform/upgrade', $runbook);

        Livewire::actingAs($admin)
            ->test(Upgrade::class)
            ->assertSee($install['title'])
            ->assertSee($install['body']);

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertForbidden()
            ->assertDontSee($install['body']);

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($admin)
            ->get($this->tenantUrl('alpha.test', '/platform/upgrade'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertOk()
            ->assertDontSee($install['body']);
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
