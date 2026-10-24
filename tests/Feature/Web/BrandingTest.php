<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Branding;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(string $role = 'owner', string $host = 'alpha.test'): array
    {
        $workspace = $this->workspace($host, $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function brandingFor(string $tenantId): TenantBranding
    {
        return TenantBranding::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->firstOrFail();
    }

    public function test_new_workspaces_start_with_the_brandworx_look(): void
    {
        $workspace = $this->signedIn();

        $branding = $this->brandingFor($workspace['tenant']->id);
        $this->assertSame('#0F5C4D', $branding->primary_color);
        $this->assertSame('#F59E0B', $branding->accent_color);
    }

    public function test_an_owner_saves_name_colors_and_support_contacts(): void
    {
        $workspace = $this->signedIn();

        Livewire::test(Branding::class)
            ->set('displayName', 'Alpha Paddle Co')
            ->set('primary', '#1d4ed8')
            ->set('accent', '#DB2777')
            ->set('supportEmail', 'Help@Alpha.test')
            ->set('supportPhone', '(555) 010-2030')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('primary', '#1D4ED8');

        $branding = $this->brandingFor($workspace['tenant']->id);
        $this->assertSame('Alpha Paddle Co', $branding->display_name);
        $this->assertSame('#1D4ED8', $branding->primary_color);
        $this->assertSame('#DB2777', $branding->accent_color);
        $this->assertSame('help@alpha.test', $branding->support_email);
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'branding.updated')->count());
    }

    public function test_a_preset_fills_both_colors(): void
    {
        $this->signedIn();

        Livewire::test(Branding::class)
            ->call('usePreset', 'Plum')
            ->assertSet('primary', '#6D28D9')
            ->assertSet('accent', '#DB2777');
    }

    public function test_bad_colors_and_phone_numbers_are_rejected(): void
    {
        $workspace = $this->signedIn();

        Livewire::test(Branding::class)
            ->set('primary', 'red;}body{display:none')
            ->call('save')
            ->assertHasErrors('primary');

        Livewire::test(Branding::class)
            ->set('supportPhone', '123')
            ->call('save')
            ->assertHasErrors('supportPhone');

        $this->assertSame('#0F5C4D', $this->brandingFor($workspace['tenant']->id)->primary_color);
    }

    public function test_a_logo_upload_is_stored_for_this_workspace_and_shown(): void
    {
        Storage::fake('local');
        $workspace = $this->signedIn();

        Livewire::test(Branding::class)
            ->set('logo', UploadedFile::fake()->image('logo.png', 300, 300))
            ->call('save')
            ->assertHasNoErrors();

        $branding = $this->brandingFor($workspace['tenant']->id);
        $this->assertStringStartsWith('branding/'.$workspace['tenant']->id.'/', (string) $branding->logo_path);

        Livewire::test(Branding::class)->call('removeLogo');
        $this->assertNull($this->brandingFor($workspace['tenant']->id)->logo_path);
    }

    public function test_staff_can_see_branding_but_not_change_it(): void
    {
        $workspace = $this->signedIn('staff');

        Livewire::test(Branding::class)
            ->assertSee('Only owners and managers can change branding.')
            ->set('primary', '#000000')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('#0F5C4D', $this->brandingFor($workspace['tenant']->id)->primary_color);
    }

    public function test_colors_reach_the_staff_app_and_the_booking_page_of_that_workspace_only(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $this->workspace('beta.test', 'owner', ['name' => 'Beta Bikes']);
        $this->rentalShop($alpha['tenant']);
        TenantBranding::withoutGlobalScope(TenantScope::class)->where('tenant_id', $alpha['tenant']->id)->update(['primary_color' => '#6D28D9']);

        $this->get($this->tenantUrl('alpha.test', '/'))->assertOk()->assertSee('--brand:#6D28D9', false);
        $this->get($this->tenantUrl('beta.test', '/staff/login'))->assertOk()->assertSee('--brand:#0F5C4D', false)->assertDontSee('#6D28D9');

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('--brand:#6D28D9', false);
    }
}
