<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Staff\QuickSearch;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Search\StaffQuickSearch;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffQuickSearchTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_search_finds_bookings_customers_and_inventory_by_group(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant']);
        $booking = $this->booking($alpha['tenant'], $shop['location'], $shop['service'], 'Ada Lovelace', 'ada@example.com', '312-555-0199');
        $asset = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Tandem kayak',
        ]);
        $this->signIn($alpha);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Lovelace')
            ->assertSee('Bookings')
            ->assertSee('Customers')
            ->assertSee('Ada Lovelace')
            ->assertSee('/staff/bookings/'.$booking->id, false)
            ->assertSee('/staff/bookings?search=ada%40example.com', false)
            ->call('openSelected')
            ->assertRedirect('/staff/bookings/'.$booking->id);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'ada@example.com')
            ->assertSee('Ada Lovelace');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', '5550199')
            ->assertSee('Ada Lovelace');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', $booking->id)
            ->assertSee('Ada Lovelace')
            ->assertSee($booking->id);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', substr($booking->id, 10, 8))
            ->assertSee($booking->id);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'kayak')
            ->assertSee('Inventory')
            ->assertSee('Single kayak')
            ->assertSee('Tandem kayak')
            ->assertSee('#pool-'.$shop['pool']->id, false)
            ->assertSee('#asset-'.$asset->id, false);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('id="pool-'.$shop['pool']->id.'"', false)
            ->assertSee('id="asset-'.$asset->id.'"', false);
    }

    public function test_another_workspace_and_a_removed_customer_are_hidden(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $shop = $this->rentalShop($alpha['tenant']);
        $betaShop = $this->rentalShop($beta['tenant']);
        $booking = $this->booking($alpha['tenant'], $shop['location'], $shop['service'], 'Ada Lovelace', 'ada@example.com', '312-555-0199');
        $this->booking($beta['tenant'], $betaShop['location'], $betaShop['service'], 'Beta Secret', 'beta@example.com', '999-555-0100');
        Asset::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => $betaShop['location']->id,
            'name' => 'Beta canoe',
        ]);

        app(TenantManager::class)->set($alpha['tenant']);
        $customer = Customer::query()->where('email', 'ada@example.com')->firstOrFail();
        $customer->forceFill([
            'name' => 'Removed customer',
            'email' => null,
            'phone' => null,
            'anonymized_at' => now(),
        ])->save();

        $this->signIn($alpha);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Secret')
            ->assertDontSee('Beta Secret')
            ->assertDontSee('beta@example.com');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'canoe')
            ->assertDontSee('Beta canoe');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Lovelace')
            ->assertDontSee('Ada Lovelace')
            ->assertDontSee('ada@example.com');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', $booking->id)
            ->assertSee($booking->id)
            ->assertDontSee('Ada Lovelace')
            ->assertDontSee('Removed customer');
    }

    public function test_a_location_limit_hides_other_locations_and_unbooked_customers(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $north = $this->rentalShop($alpha['tenant']);
        $south = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'South dock',
            'timezone' => 'UTC',
        ]);
        $southService = Service::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South rental',
            'kind' => 'rental',
        ]);
        $this->booking($alpha['tenant'], $north['location'], $north['service'], 'North Guest', 'north@example.com', '312-555-0199');
        $this->booking($alpha['tenant'], $south, $southService, 'South Guest', 'south@example.com', '404-555-0100');
        Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $north['location']->id,
            'name' => 'North pontoon',
        ]);
        Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South canoe',
        ]);
        app(TenantManager::class)->set($alpha['tenant']);
        Customer::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Lobby Patron',
            'email' => 'lobby@example.com',
        ]);
        $this->limit($alpha['membership'], $north['location']->id);
        $this->signIn($alpha);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Guest')
            ->assertSee('North Guest')
            ->assertDontSee('South Guest');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'pontoon')
            ->assertSee('North pontoon');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'canoe')
            ->assertDontSee('South canoe');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Patron')
            ->assertDontSee('Lobby Patron');

        $owner = $this->workspace('owner.test');
        Membership::factory()->role('owner')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $owner['user']->id,
        ]);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($owner['user']);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Patron')
            ->assertSee('Lobby Patron');

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'South')
            ->assertSee('South Guest')
            ->assertSee('South canoe');
    }

    public function test_inventory_results_are_paged_on_the_server(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant']);

        for ($i = 1; $i <= 9; $i++) {
            Asset::factory()->create([
                'tenant_id' => $alpha['tenant']->id,
                'location_id' => $shop['location']->id,
                'name' => sprintf('Skiff %02d', $i),
            ]);
        }

        $this->signIn($alpha);

        Livewire::test(QuickSearch::class)
            ->set('open', true)
            ->set('query', 'Skiff')
            ->assertSee('Skiff 01')
            ->assertSee('Skiff 08')
            ->assertDontSee('Skiff 09')
            ->assertSee('1 of 2')
            ->call('pageFor', 'inventory', 2)
            ->assertSee('Skiff 09')
            ->assertDontSee('Skiff 01')
            ->assertSee('2 of 2');
    }

    public function test_lookups_use_prefix_indexes(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $search = app(StaffQuickSearch::class);
        $location = Location::factory()->create(['tenant_id' => $alpha['tenant']->id, 'name' => 'North dock']);

        $customers = $search->tokenLookup('customer', 'ada', null);
        $this->assertIndexed($customers, 'staff_search_token_idx');

        $bookings = $search->tokenLookup('booking', 'lovelace', [$location->id]);
        $this->assertIndexed($bookings, 'staff_search_location_token_idx');

        $links = $search->bookingsForCustomers([(string) str()->ulid()]);
        $this->assertIndexed($links, 'bookings_tenant_customer_idx');
    }

    public function test_the_header_offers_search_and_shortcuts_that_pause_while_typing(): void
    {
        $alpha = $this->workspace('alpha.test');
        $css = (string) file_get_contents(resource_path('ui/app.css'));
        $fullscreen = strpos($css, ".staff-search {\n    position: relative;");
        $desktop = strpos($css, '@media (min-width: 720px)');

        $this->assertNotFalse($fullscreen);
        $this->assertNotFalse($desktop);
        $this->assertLessThan($desktop, $fullscreen);
        $this->assertStringContainsString('height: 100%;', $css);

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('aria-label="Search"', false)
            ->assertSee('aria-keyshortcuts="Control+K Meta+K"', false)
            ->assertSee('data-new-booking="1"', false)
            ->assertSee('staff-search-toggle', false)
            ->assertSee('staff-shortcuts-help', false)
            ->assertSee("tag === 'INPUT'", false)
            ->assertSee('isContentEditable', false)
            ->assertSee('dataset.newBooking', false);

        $this->signIn($alpha);

        Livewire::test(QuickSearch::class)
            ->call('showHelp')
            ->assertSee('Keyboard shortcuts')
            ->assertSee('Bookings')
            ->assertSee('Dashboard')
            ->assertSee('New booking')
            ->assertSee('Shortcuts pause while you are typing in a field.');
    }

    private function assertIndexed(Builder $query, string $index): void
    {
        foreach ($query->getBindings() as $binding) {
            if (is_string($binding)) {
                $this->assertFalse(str_starts_with($binding, '%'), 'A leading wildcard cannot use the index: '.$binding);
            }
        }

        $plan = DB::select('explain '.$query->toSql(), $query->getBindings());
        $this->assertNotEmpty($plan);
        $row = (array) $plan[0];
        $possible = (string) ($row['possible_keys'] ?? $row['Possible_keys'] ?? '');
        $this->assertStringContainsString($index, $possible);
    }

    private function signIn(array $workspace): void
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
    }

    private function booking(Tenant $tenant, Location $location, Service $service, string $name, string $email, ?string $phone = null): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
        ]);
        $starts = CarbonImmutable::parse('2026-06-16 15:00:00', 'UTC');

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
            'participants' => 2,
            'payload_hash' => hash('sha256', $email),
        ]);
    }

    private function limit(Membership $membership, string ...$locationIds): void
    {
        $membership->locations_restricted = true;
        $membership->save();

        foreach ($locationIds as $locationId) {
            MembershipLocation::query()->create([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'location_id' => $locationId,
            ]);
        }

        app(StaffLocationAccess::class)->forget();
    }
}
