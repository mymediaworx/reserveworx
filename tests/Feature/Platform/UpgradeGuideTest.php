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

class UpgradeGuideTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_platform_admin_can_read_the_upgrade_procedure_and_nothing_is_written(): void
    {
        $admin = User::factory()->create([
            'name' => 'Ada <script>alert(1)</script>',
        ]);
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $guide = app(UpgradeGuide::class);
        $before = $this->counts();

        $page = $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertOk()
            ->assertSee('Upgrade')
            ->assertSee($guide->intro())
            ->assertSee('Ada &lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertSee('href="/platform/upgrade"', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertDontSee('<script>', false)
            ->assertSee('Restore the database')
            ->assertSee('reserveworx:restore-backup')
            ->assertSee('Nothing is written until --force is passed.')
            ->assertSee('This page does not run a restore.')
            ->assertSee('A restore has not been run on the live SiteGround database.')
            ->assertDontSee('sk_live')
            ->assertDontSee('whsec_')
            ->assertDontSee('SG_SSH_KEY')
            ->assertDontSee('3%');

        foreach ($guide->steps() as $step) {
            $page->assertSee($step['title'])->assertSee($step['body']);
        }

        $this->assertSame($before, $this->counts());

        Livewire::actingAs($admin)
            ->test(Upgrade::class)
            ->assertSee('Wait for CI')
            ->assertSee('does not undo migrations');
    }

    public function test_the_published_deploy_doc_uses_the_same_sentences(): void
    {
        $deploy = (string) file_get_contents(base_path('docs/DEPLOY.md'));
        $runbook = (string) file_get_contents(base_path('docs/RUNBOOK.md'));
        $guide = app(UpgradeGuide::class);

        $this->assertStringContainsString('## Upgrade', $deploy);
        $this->assertStringContainsString($guide->intro(), $deploy);

        foreach ($guide->steps() as $step) {
            $this->assertStringContainsString($step['title'], $deploy);
            $this->assertStringContainsString($step['body'], $deploy);
        }

        $this->assertStringContainsString('/platform/upgrade', $runbook);
        $this->assertStringContainsString('does not run that command', $runbook);
        $this->assertStringNotContainsString('sk_live', $deploy);
        $this->assertStringNotContainsString('whsec_', $deploy);
    }

    public function test_a_platform_admin_can_read_how_to_start_a_deploy_and_nothing_is_written(): void
    {
        $step = collect(app(UpgradeGuide::class)->steps())->firstWhere('title', 'Start a deploy');
        $this->assertIsArray($step);

        $this->get('http://app.reserveworx.com/platform/upgrade')
            ->assertRedirect('/platform/login');

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $before = $this->counts();

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertOk()
            ->assertSee($step['title'])
            ->assertSee($step['body'])
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('max-width: 390px', false)
            ->assertDontSee('3%')
            ->assertDontSee('sk_live')
            ->assertDontSee('whsec_');

        $this->assertSame($before, $this->counts());

        $deploy = (string) file_get_contents(base_path('docs/DEPLOY.md'));
        $runbook = (string) file_get_contents(base_path('docs/RUNBOOK.md'));
        $this->assertStringContainsString('### '.$step['title'], $deploy);
        $this->assertStringContainsString($step['body'], $deploy);
        $this->assertStringContainsString($step['body'], $runbook);
        $this->assertStringContainsString('/platform/upgrade', $runbook);

        Livewire::actingAs($admin)
            ->test(Upgrade::class)
            ->assertSee($step['title'])
            ->assertSee($step['body']);

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertForbidden()
            ->assertDontSee($step['body']);

        $workspace = $this->workspace('alpha.test');
        $this->actingAs($admin)
            ->get($this->tenantUrl('alpha.test', '/platform/upgrade'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/help'))
            ->assertOk()
            ->assertDontSee($step['body']);
    }

    public function test_only_a_platform_admin_on_the_platform_host_can_open_the_page(): void
    {
        $this->get('http://app.reserveworx.com/platform/upgrade')
            ->assertRedirect('/platform/login');

        $member = User::factory()->create();
        $this->actingAs($member)
            ->get('http://app.reserveworx.com/platform/upgrade')
            ->assertForbidden()
            ->assertSee('Platform administrator access is required.');

        Livewire::actingAs($member)->test(Upgrade::class)->assertForbidden();

        $workspace = $this->workspace('alpha.test');
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)
            ->get($this->tenantUrl('alpha.test', '/platform/upgrade'))
            ->assertNotFound();

        $this->actingAs($workspace['user'])
            ->get($this->tenantUrl('alpha.test', '/platform/upgrade'))
            ->assertNotFound();
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
