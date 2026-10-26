<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Dashboard;
use App\Livewire\Staff\Settings;
use App\Livewire\Staff\SetupGuidePage;
use App\Models\CancellationPolicy;
use App\Models\LocationTaxRule;
use App\Models\PriceRule;
use App\Models\StripeAccount;
use App\Models\TenantSetting;
use App\Models\TenantSetupGuide;
use App\Services\Onboarding\SetupGuide;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class SetupGuideTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    private function signIn(array $workspace): void
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
    }

    /** @return array<string, string> */
    private function states(array $workspace): array
    {
        return collect(app(SetupGuide::class)->steps($workspace['tenant']->fresh()))->pluck('state', 'key')->all();
    }

    public function test_a_new_workspace_starts_with_every_step_to_do_and_the_guide_on_home(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->signIn($alpha);

        $this->assertSame(['todo'], array_values(array_unique($this->states($alpha))));
        Livewire::test(SetupGuidePage::class)
            ->assertSee('0 of 13')
            ->assertSee('Ask support')
            ->assertSee('Open locations')
            ->assertSee('Hide setup guide');
        Livewire::test(Dashboard::class)->assertSee('Open setup guide')->assertSee('0 of 13');
    }

    public function test_steps_are_detected_from_real_rows(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant']);
        $this->signIn($alpha);
        $before = $this->states($alpha);
        $this->assertSame('done', $before['services']);
        $this->assertSame('todo', $before['payments']);

        StripeAccount::query()->create(['tenant_id' => $alpha['tenant']->id, 'stripe_account_id' => 'acct_1', 'card_payments_status' => 'active', 'livemode' => false]);
        $this->assertSame('done', $this->states($alpha)['payments']);
    }

    public function test_owner_can_mark_done_skip_and_undo_without_touching_money_settings(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->signIn($alpha);
        $settingsBefore = TenantSetting::query()->get()->toArray();

        Livewire::test(SetupGuidePage::class)
            ->call('markDone', 'share')
            ->call('skip', 'deposit')
            ->assertSee('2 of 13');
        $this->assertSame('done', $this->states($alpha)['share']);
        $this->assertSame('skipped', $this->states($alpha)['deposit']);

        Livewire::test(SetupGuidePage::class)->call('undo', 'share');
        $this->assertSame('todo', $this->states($alpha)['share']);

        $this->assertSame($settingsBefore, TenantSetting::query()->get()->toArray());
        $this->assertSame(0, PriceRule::withoutGlobalScopes()->count());
        $this->assertSame(0, LocationTaxRule::withoutGlobalScopes()->count());
        $this->assertSame(0, CancellationPolicy::withoutGlobalScopes()->count());
    }

    public function test_unknown_step_is_rejected(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->signIn($alpha);

        Livewire::test(SetupGuidePage::class)->call('markDone', 'nope')->assertStatus(404);
    }

    public function test_hide_and_show_again_per_workspace(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $this->signIn($alpha);

        Livewire::test(Dashboard::class)->call('hideSetupGuide')->assertDontSee('Open setup guide');
        $this->assertTrue(TenantSetupGuide::withoutGlobalScopes()->where('tenant_id', $alpha['tenant']->id)->value('hidden'));
        $this->assertFalse(app(SetupGuide::class)->isHidden($beta['tenant']));

        Livewire::test(Settings::class)->assertSee('Show setup guide')->call('toggleSetupGuide');
        $this->assertFalse(app(SetupGuide::class)->isHidden($alpha['tenant']->fresh()));
        Livewire::test(Dashboard::class)->assertSee('Open setup guide');
    }

    public function test_older_workspaces_are_hidden_by_default(): void
    {
        $old = $this->workspace('old.test', 'owner', ['created_at' => '2026-10-20 12:00:00']);

        $this->assertTrue(app(SetupGuide::class)->isHidden($old['tenant']));
    }

    public function test_manager_sees_it_and_staff_do_not(): void
    {
        $manager = $this->workspace('alpha.test', 'manager');
        $this->signIn($manager);
        Livewire::test(SetupGuidePage::class)->assertOk();
        app(TenantManager::class)->forget();

        $staff = $this->workspace('beta.test', 'staff');
        $this->signIn($staff);
        Livewire::test(SetupGuidePage::class)->assertForbidden();
        Livewire::test(Dashboard::class)->assertDontSee('Open setup guide');
    }

    public function test_the_page_renders_over_http(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->actingAs($alpha['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup'))
            ->assertOk()
            ->assertSee('Setup guide')
            ->assertSee('/staff/waivers', false);
    }
}
