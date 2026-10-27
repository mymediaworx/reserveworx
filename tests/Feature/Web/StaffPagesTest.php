<?php

namespace Tests\Feature\Web;

use App\Models\Booking;
use App\Models\StaffUndoAction;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffPagesTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    private function heldBooking(array $workspace, string $serviceId, string $name = 'Ada Lovelace'): Booking
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $start = CarbonImmutable::now('UTC')->addDays(2)->setTime(15, 0);
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $serviceId,
            $start,
            $start->addHour(),
            2,
            null,
            $name,
            'ada@example.com',
        ));
        app(TenantManager::class)->forget();

        return $booking;
    }

    public function test_every_staff_page_renders_for_an_owner(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $shop = $this->rentalShop($alpha['tenant']);
        $booking = $this->heldBooking($alpha, $shop['service']->id);
        $this->actingAs($alpha['user']);

        foreach ([
            '/staff' => 'Today’s schedule',
            '/staff/calendar' => 'Calendar',
            '/staff/schedule' => 'Schedule',
            '/staff/bookings' => 'Ada Lovelace',
            '/staff/bookings/new' => 'Kayak rental',
            '/staff/bookings/'.$booking->id => 'Calculate price',
            '/staff/setup/locations' => 'North dock',
            '/staff/closures' => 'Closures',
            '/staff/setup/resources' => 'Single kayak',
            '/staff/setup/services' => 'Kayak rental',
            '/staff/promo-codes' => 'Promo codes',
            '/staff/add-ons' => 'No add-ons yet.',
            '/staff/import' => 'Upload a CSV',
            '/staff/customers' => 'Customer records',
            '/staff/setup/services/'.$shop['service']->id => 'Per paddler',
            '/staff/settings' => 'Who pays the booking fee',
            '/staff/team' => 'Invite by email',
            '/staff/feedback' => 'No ratings yet.',
            '/staff/check-in' => "Today's arrivals",
            '/staff/returns' => 'Due back today',
            '/staff/manifest' => 'Dispatch manifest',
            '/staff/load-sheet' => 'Daily load sheet',
            '/staff/reports' => 'Daily summaries have not been built for this range yet.',
            '/staff/waitlist' => 'No one is waiting on this date.',
            '/staff/menu' => 'Set up your business',
            '/staff/branding' => 'Customer support',
            '/staff/share' => 'How do you want to share it?',
        ] as $path => $text) {
            $this->get($this->tenantUrl('alpha.test', $path))
                ->assertOk()
                ->assertSee($text, false);
        }
    }

    public function test_an_empty_workspace_is_guided_to_set_up(): void
    {
        $alpha = $this->workspace('alpha.test');

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('get you taking bookings');
    }

    public function test_staff_can_see_setup_but_not_change_it(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $this->rentalShop($alpha['tenant']);

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Single kayak')
            ->assertDontSee('Add gear');

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/services'))
            ->assertOk()
            ->assertDontSee('Add a service');
    }

    public function test_a_booking_from_another_workspace_is_not_found(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $betaShop = $this->rentalShop($beta['tenant']);
        $betaBooking = $this->heldBooking($beta, $betaShop['service']->id, 'Beta Customer');
        $this->rentalShop($alpha['tenant']);

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$betaBooking->id))
            ->assertNotFound();

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/services/'.$betaShop['service']->id))
            ->assertNotFound();

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings'))
            ->assertOk()
            ->assertDontSee('Beta Customer');
    }

    public function test_a_component_replayed_on_another_workspace_domain_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $shop = $this->rentalShop($alpha['tenant']);
        $booking = $this->heldBooking($alpha, $shop['service']->id);

        $html = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$booking->id))
            ->assertOk()
            ->getContent();

        preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $match);
        $this->assertNotEmpty($match, 'The page renders a Livewire component.');
        $snapshot = html_entity_decode($match[1]);
        $uri = app('livewire')->getUpdateUri();
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'cancelBooking', 'params' => []]]]]];
        $headers = ['X-Livewire' => '1', 'X-CSRF-TOKEN' => csrf_token()];

        // Same user, same snapshot, but posted to beta's domain: the tenant comes from
        // the host, and the user has no seat there.
        $this->withHeaders($headers)
            ->postJson($this->tenantUrl('beta.test', $uri), $payload)
            ->assertForbidden();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('held', Booking::query()->find($booking->id)?->status->value);
        app(TenantManager::class)->forget();

        // Also refused for a beta member replaying alpha's snapshot on beta's domain.
        $this->actingAs($beta['user'])
            ->withHeaders($headers)
            ->postJson($this->tenantUrl('beta.test', $uri), $payload)
            ->assertNotFound();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('held', Booking::query()->find($booking->id)?->status->value);
        app(TenantManager::class)->forget();

        // Control: the same call on alpha's own domain by alpha's member works.
        $this->actingAs($alpha['user'])
            ->withHeaders($headers)
            ->postJson($this->tenantUrl('alpha.test', $uri), $payload)
            ->assertOk();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('held', Booking::query()->find($booking->id)?->status->value);
        $this->assertSame(1, StaffUndoAction::query()->count());
        Carbon::setTestNow(now()->addSeconds(11));
        $this->artisan('undo:commit')->assertSuccessful();
        Carbon::setTestNow();
        $this->assertSame('cancelled', Booking::query()->find($booking->id)?->status->value);
        app(TenantManager::class)->forget();
    }

    public function test_a_narrow_screen_scrolls_every_staff_page_past_the_tab_bar(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $this->rentalShop($alpha['tenant']);
        $this->actingAs($alpha['user']);

        foreach ([
            '/staff' => 'Today’s schedule',
            '/staff/menu' => 'Set up your business',
            '/staff/settings' => 'Who pays the booking fee',
            '/staff/calendar' => 'Calendar',
        ] as $path => $text) {
            $html = (string) $this->get($this->tenantUrl('alpha.test', $path))
                ->assertOk()
                ->assertSee($text, false)
                ->assertSee('class="app-bar"', false)
                ->assertSee('class="location-switcher"', false)
                ->assertSee('class="app-main"', false)
                ->assertSee('class="app-content"', false)
                ->assertSee('class="tab-bar"', false)
                ->assertSee('class="fab"', false)
                ->getContent();

            $this->assertStringNotContainsString('resources-page', $html);
            $this->assertStringNotContainsString('addons-page', $html);
        }

        $newBooking = (string) $this->get($this->tenantUrl('alpha.test', '/staff/bookings/new'))
            ->assertOk()
            ->assertSee('New booking', false)
            ->assertSee('class="app-bar"', false)
            ->assertSee('class="tab-bar"', false)
            ->getContent();
        $this->assertStringNotContainsString('class="fab"', $newBooking);

        $css = (string) file_get_contents(resource_path('ui/app.css'));
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1023px\) \{\s*body\.app:has\(\.tab-bar\) \.app-bar \{\s*position: static;\s*\}.*?body\.app:has\(\.tab-bar\) \{\s*height: auto;[^}]*overflow: visible;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1023px\) \{.*?body\.app:has\(\.tab-bar\) \.app-main \{[^}]*max-height: none;[^}]*overflow: visible;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 1023px\) \{.*?body\.app:has\(\.tab-bar\) \.app-content \{\s*padding-bottom: calc\(var\(--tabbar\) \+ var\(--safe-bottom\) \+ 5\.5rem\);/s',
            $css,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 1023px\) \{[^@]*resources-page/s',
            $css,
        );
        $this->assertMatchesRegularExpression('/\.tab-bar \{\s*position: fixed;/s', $css);
        $this->assertMatchesRegularExpression('/\.fab \{\s*position: fixed;/s', $css);
        $this->assertDoesNotMatchRegularExpression(
            '/\.location-switcher\s*\{[^}]*position:\s*(fixed|sticky)/s',
            $css,
        );
        $this->assertStringNotContainsString(
            'max-height: calc(100dvh - var(--tabbar) - var(--safe-bottom) - 3.75rem - 2rem)',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/@media \(min-width: 1024px\) \{.*\.tab-bar, \.fab \{ display: none; \}/s',
            $css,
        );

        $scroll = strpos($css, 'body.app:has(.tab-bar)');
        $search = strpos($css, 'body.app:has(.staff-search-layer) { overflow: hidden; }');
        $this->assertNotFalse($scroll);
        $this->assertNotFalse($search);
        $this->assertLessThan($search, $scroll);
    }
}
