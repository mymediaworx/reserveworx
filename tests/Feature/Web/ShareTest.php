<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Share;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Models\User;
use App\Models\WidgetToken;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ShareTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function signIn(array $workspace): void
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
    }

    public function test_the_main_link_is_the_workspace_address_and_opens_the_booking_page(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $this->rentalShop($alpha['tenant']);
        $this->signIn($alpha);

        Livewire::test(Share::class)
            ->assertViewHas('url', 'http://alpha.test/')
            ->assertSee('http://alpha.test/');
        app(TenantManager::class)->forget();

        $this->get($this->tenantUrl('alpha.test', '/'))->assertOk()->assertSee('Kayak rental');
    }

    public function test_one_service_gets_its_own_link_that_works(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant']);
        $this->signIn($alpha);
        $hosted = app(WidgetTokens::class)->hostedPlain($alpha['tenant']);

        $url = Livewire::test(Share::class, ['target' => $shop['service']->id])->viewData('url');
        app(TenantManager::class)->forget();

        $this->assertSame('http://alpha.test/book/'.$hosted.'/services/'.$shop['service']->id, $url);
        $this->get((string) $url)->assertOk()->assertSee('Kayak rental');
    }

    public function test_a_service_from_another_workspace_falls_back_to_everything(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $betaShop = $this->rentalShop($beta['tenant']);
        $this->signIn($alpha);

        Livewire::test(Share::class, ['target' => $betaShop['service']->id])
            ->assertSet('target', 'all')
            ->assertViewHas('url', 'http://alpha.test/')
            ->assertDontSee($betaShop['service']->id);
    }

    public function test_every_format_produces_code_that_points_at_this_workspace(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $this->rentalShop($alpha['tenant']);
        $this->signIn($alpha);

        $component = Livewire::test(Share::class)->set('buttonText', 'Reserve <now>');
        $snippets = $component->viewData('snippets');

        $this->assertStringContainsString('href="http://alpha.test/"', $snippets['button']);
        $this->assertStringContainsString('Reserve &lt;now&gt;', $snippets['button']);
        $this->assertStringNotContainsString('<now>', $snippets['button']);
        $this->assertStringContainsString('<iframe src="http://alpha.test/"', $snippets['inline']);
        $this->assertStringContainsString('data-reserveworx', $snippets['inline']);
        $this->assertStringContainsString('<script src="http://alpha.test/embed.js" async></script>', $snippets['inline']);
        $this->assertStringContainsString('data-reserveworx-popup', $snippets['popup']);
        $this->assertStringContainsString('http://alpha.test/embed.js', $snippets['popup']);

        $component->call('choose', 'popup')->assertSet('format', 'popup');
        $component->call('choose', 'nonsense')->assertSet('format', 'link');
    }

    public function test_the_button_uses_the_workspace_color_with_readable_text(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->signIn($alpha);
        TenantBranding::query()->update(['primary_color' => '#FDE68A']);

        $snippet = Livewire::test(Share::class)->viewData('snippets')['button'];

        $this->assertStringContainsString('background:#FDE68A;color:#111827', $snippet);
    }

    public function test_the_embed_script_is_served_without_a_session_cookie(): void
    {
        $this->workspace('alpha.test');

        $response = $this->get($this->tenantUrl('alpha.test', '/embed.js'))->assertOk();

        $this->assertStringContainsString('javascript', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('reserveworx:height', (string) $response->getContent());
        $this->assertEmpty($response->headers->getCookies());
    }

    public function test_the_booking_page_reports_its_height_and_pays_outside_the_frame(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->rentalShop($alpha['tenant']);

        $this->get($this->tenantUrl('alpha.test', '/'))
            ->assertOk()
            ->assertSee("type: 'reserveworx:height'", false);

        $checkout = file_get_contents(resource_path('views/widget/checkout.blade.php'));
        $this->assertStringContainsString('action="{{ $payUrl }}" target="_top"', (string) $checkout);
    }

    public function test_more_links_are_shown_once_hashed_and_can_be_turned_off(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->rentalShop($alpha['tenant']);
        $this->signIn($alpha);

        $component = Livewire::test(Share::class)->set('name', 'Partner hotel')->call('createLink')->assertHasNoErrors();
        $url = (string) $component->get('newUrl');
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->assertMatchesRegularExpression('#^/book/[A-Za-z0-9]{40}$#', $path);
        $plain = substr($path, strlen('/book/'));
        $token = WidgetToken::query()->where('name', 'Partner hotel')->firstOrFail();
        $this->assertSame(hash('sha256', $plain), $token->token_hash);

        Livewire::test(Share::class)->assertDontSee($plain)->call('revoke', $token->id);
        app(TenantManager::class)->forget();

        $this->get($this->tenantUrl('alpha.test', $path))->assertNotFound();
    }

    public function test_the_old_booking_links_address_redirects(): void
    {
        $alpha = $this->workspace('alpha.test');

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/booking-links'))
            ->assertRedirect('/staff/share');
    }

    public function test_a_link_from_one_workspace_does_not_work_on_another(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->workspace('beta.test');
        $shop = $this->rentalShop($alpha['tenant']);
        $hosted = app(WidgetTokens::class)->hostedPlain($alpha['tenant']);

        $this->assertInstanceOf(Service::class, $shop['service']);
        $this->get($this->tenantUrl('beta.test', '/book/'.$hosted.'/services/'.$shop['service']->id))->assertNotFound();
    }
}
