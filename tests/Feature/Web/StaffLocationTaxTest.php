<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\LocationTax;
use App\Livewire\Staff\NewBooking;
use App\Livewire\Staff\Setup\Locations;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffLocationTaxTest extends TestCase
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
    private function signedIn(string $role = 'owner'): array
    {
        $workspace = $this->workspace('alpha.test', $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    public function test_an_owner_adds_edits_and_removes_named_rates_for_one_location(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $other = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South dock',
        ]);
        $css = (string) file_get_contents(resource_path('ui/app.css'));
        $this->assertMatchesRegularExpression('/\.tax-page[\s\S]*@media \(max-width: 420px\)/', $css);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/locations'))
            ->assertOk()
            ->assertSee('/staff/locations/'.$shop['location']->id.'/tax', false);

        app(TenantManager::class)->set($workspace['tenant']);

        $this->get($this->tenantUrl('alpha.test', '/staff/locations/'.$shop['location']->id.'/tax'))
            ->assertOk()
            ->assertSee('Add a tax rate')
            ->assertSee('class="page tax-page"', false);

        app(TenantManager::class)->set($workspace['tenant']);

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->set('name', 'State sales tax')
            ->set('percent', '8.25')
            ->set('appliesToRentals', true)
            ->set('appliesToAddOns', true)
            ->set('appliesToFees', false)
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('State sales tax')
            ->assertSee('8.25%')
            ->assertSee('Rentals, Add-ons');

        $rule = LocationTaxRule::query()->where('name', 'State sales tax')->firstOrFail();
        $this->assertSame($shop['location']->id, $rule->location_id);
        $this->assertSame(825, $rule->percent_bps);
        $this->assertTrue($rule->applies_to_rentals);
        $this->assertTrue($rule->applies_to_add_ons);
        $this->assertFalse($rule->applies_to_fees);

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->set('name', 'State sales tax')
            ->set('percent', '1')
            ->call('create')
            ->assertHasErrors('name');

        Livewire::test(LocationTax::class, ['location' => $other])
            ->set('name', 'State sales tax')
            ->set('percent', '1.5')
            ->call('create')
            ->assertHasNoErrors();

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->set('name', 'County tax')
            ->set('percent', '8.875')
            ->call('create')
            ->assertHasErrors('percent');

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->set('name', 'County tax')
            ->set('percent', '1')
            ->set('appliesToRentals', false)
            ->set('appliesToAddOns', false)
            ->set('appliesToFees', false)
            ->call('create')
            ->assertHasErrors('appliesToRentals');

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->call('edit', $rule->id)
            ->set('name', 'County tax')
            ->set('percent', '1.50')
            ->set('appliesToRentals', true)
            ->set('appliesToAddOns', false)
            ->set('appliesToFees', true)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('County tax')
            ->assertSee('1.50%')
            ->assertSee('Rentals, Fees');

        $rule->refresh();
        $this->assertSame('County tax', $rule->name);
        $this->assertSame(150, $rule->percent_bps);
        $this->assertTrue($rule->applies_to_fees);
        $this->assertFalse($rule->applies_to_add_ons);

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->call('remove', $rule->id)
            ->assertSee('No tax rates');

        $this->assertNull(LocationTaxRule::query()->find($rule->id));
        $this->assertSame(1, LocationTaxRule::query()->where('location_id', $other->id)->count());

        $logs = AuditLog::withoutGlobalScope(TenantScope::class);
        $this->assertSame(2, (clone $logs)->where('action', 'location_tax_rule.created')->count());
        $this->assertSame(1, (clone $logs)->where('action', 'location_tax_rule.updated')->count());
        $this->assertSame(1, (clone $logs)->where('action', 'location_tax_rule.deleted')->count());
    }

    public function test_a_manager_can_set_tax(): void
    {
        $manager = $this->signedIn('manager');
        $shop = $this->rentalShop($manager['tenant'], 4, 'UTC');

        Livewire::test(Locations::class)->assertSee('Tax');
        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->set('name', 'City tax')
            ->set('percent', '1')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('City tax');

        $this->assertSame(100, LocationTaxRule::query()->where('name', 'City tax')->firstOrFail()->percent_bps);
    }

    public function test_staff_cannot_set_tax_or_see_the_link(): void
    {
        $staff = $this->signedIn('staff');
        $shop = $this->rentalShop($staff['tenant'], 4, 'UTC');

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/locations'))
            ->assertOk()
            ->assertDontSee('/staff/locations/'.$shop['location']->id.'/tax', false);

        app(TenantManager::class)->set($staff['tenant']);

        $this->get($this->tenantUrl('alpha.test', '/staff/locations/'.$shop['location']->id.'/tax'))
            ->assertForbidden();

        app(TenantManager::class)->set($staff['tenant']);

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->assertForbidden();

        $this->assertSame(0, LocationTaxRule::query()->count());
    }

    public function test_another_workspaces_location_is_not_found(): void
    {
        $this->signedIn();
        $beta = $this->workspace('beta.test');
        $foreign = Location::factory()->create(['tenant_id' => $beta['tenant']->id, 'name' => 'Beta dock']);

        $this->get($this->tenantUrl('alpha.test', '/staff/locations/'.$foreign->id.'/tax'))
            ->assertNotFound();
    }

    public function test_an_owner_can_tax_the_booking_fee_at_one_location(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $other = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South dock',
        ]);

        $this->get($this->tenantUrl('alpha.test', '/staff/locations/'.$shop['location']->id.'/tax'))
            ->assertOk()
            ->assertSee('Tax the booking fee')
            ->assertSee('data-booking-fee-tax="off"', false)
            ->assertSee('Whether a jurisdiction taxes that fee is still open.');

        app(TenantManager::class)->set($workspace['tenant']);

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->assertSet('bookingFeeTaxable', false)
            ->set('bookingFeeTaxable', true)
            ->assertSet('bookingFeeTaxable', true)
            ->assertSee('The booking fee is taxed at this location.')
            ->assertSee('data-booking-fee-tax="on"', false);

        $this->assertTrue($shop['location']->fresh()->booking_fee_taxable);
        $this->assertFalse($other->fresh()->booking_fee_taxable);

        $log = AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('action', 'location.booking_fee_tax_updated')
            ->sole();
        $this->assertFalse($log->properties['before']);
        $this->assertTrue($log->properties['after']);

        Livewire::test(LocationTax::class, ['location' => $shop['location']])
            ->set('bookingFeeTaxable', false)
            ->assertSee('The booking fee is not taxed at this location.');

        $this->assertFalse($shop['location']->fresh()->booking_fee_taxable);
        $this->assertSame(2, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'location.booking_fee_tax_updated')->count());
    }

    public function test_booking_review_uses_the_location_rates_instead_of_a_typed_percent(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        LocationTaxRule::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'State sales tax',
            'percent_bps' => 825,
            'applies_to_rentals' => true,
            'applies_to_add_ons' => false,
            'applies_to_fees' => false,
        ]);
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(14, 0);

        Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->set('date', $start->toDateString())
            ->set('participants', 1)
            ->set('startsAt', $start->toIso8601String())
            ->set('customerName', 'Ada Lovelace')
            ->set('customerEmail', 'ada@example.com')
            ->call('book')
            ->assertHasNoErrors();

        $booking = Booking::query()->firstOrFail();

        Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->assertSee('Tax comes from this location')
            ->assertDontSee('Sales tax (%)')
            ->set('taxPercent', '99')
            ->call('price')
            ->assertSet('problem', null)
            ->assertSee('State sales tax')
            ->assertSee('$2.89')
            ->assertSee('$1.05')
            ->assertSee('$38.94')
            ->assertDontSee('$34.65');
    }
}
