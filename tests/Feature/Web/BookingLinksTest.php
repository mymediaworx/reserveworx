<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\BookingLinks;
use App\Models\WidgetToken;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class BookingLinksTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    private function makeLink(array $workspace, string $host): string
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
        $url = Livewire::test(BookingLinks::class)
            ->set('name', 'Website')
            ->call('create')
            ->assertHasNoErrors()
            ->get('newUrl');
        app(TenantManager::class)->forget();

        $this->assertIsString($url);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $this->assertMatchesRegularExpression('#^/book/[A-Za-z0-9]{40}$#', $path);

        return $path;
    }

    public function test_an_owner_makes_a_link_that_opens_the_booking_page(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $this->rentalShop($alpha['tenant']);
        $path = $this->makeLink($alpha, 'alpha.test');

        $this->get($this->tenantUrl('alpha.test', $path))
            ->assertOk()
            ->assertSee('Kayak rental');
    }

    public function test_the_link_is_shown_once_and_only_its_hash_is_stored(): void
    {
        $alpha = $this->workspace('alpha.test');
        $path = $this->makeLink($alpha, 'alpha.test');
        $plain = substr($path, strlen('/book/'));

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(hash('sha256', $plain), WidgetToken::query()->value('token_hash'));

        $this->actingAs($alpha['user']);
        Livewire::test(BookingLinks::class)->assertSet('newUrl', null)->assertDontSee($plain);
    }

    public function test_a_turned_off_link_stops_working(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->rentalShop($alpha['tenant']);
        $path = $this->makeLink($alpha, 'alpha.test');

        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);
        Livewire::test(BookingLinks::class)->call('revoke', WidgetToken::query()->value('id'));
        app(TenantManager::class)->forget();

        $this->get($this->tenantUrl('alpha.test', $path))->assertNotFound();
    }

    public function test_a_link_only_works_on_its_own_workspace_domain(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->workspace('beta.test');
        $path = $this->makeLink($alpha, 'alpha.test');

        $this->get($this->tenantUrl('beta.test', $path))->assertNotFound();
    }

    public function test_front_desk_staff_can_make_links(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        // The seeded staff role has bookings.manage, the same right the widget-token API checks.
        Livewire::test(BookingLinks::class)->set('name', 'Front desk')->call('create')->assertHasNoErrors();
        $this->assertSame(1, WidgetToken::query()->count());
    }
}
