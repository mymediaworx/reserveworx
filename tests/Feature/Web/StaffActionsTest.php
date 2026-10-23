<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\NewBooking;
use App\Livewire\Staff\Settings;
use App\Livewire\Staff\Setup\Locations;
use App\Livewire\Staff\Setup\Resources;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Livewire\Staff\Setup\Services;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

/**
 * Drives the staff screens the way a person would. Livewire::test runs the
 * component directly, so the tenant is set the way ResolveTenant sets it.
 */
class StaffActionsTest extends TestCase
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

    public function test_an_owner_sets_up_a_shop_from_scratch(): void
    {
        $this->signedIn();

        Livewire::test(Locations::class)
            ->set('name', 'North dock')
            ->set('timezone', 'America/New_York')
            ->call('create')
            ->assertHasNoErrors();
        $location = Location::query()->firstOrFail();

        Livewire::test(Resources::class)
            ->set('kind', 'pool')
            ->set('locationId', $location->id)
            ->set('name', 'Single kayak')
            ->set('quantity', 6)
            ->call('create')
            ->assertHasNoErrors();
        $pool = Pool::query()->firstOrFail();
        $this->assertSame(6, $pool->quantity);

        Livewire::test(Services::class)
            ->set('name', 'Kayak rental')
            ->set('kind', 'rental')
            ->set('locationId', $location->id)
            ->call('create')
            ->assertHasNoErrors();
        $service = Service::query()->firstOrFail();

        Livewire::test(ServiceDetail::class, ['service' => $service->id])
            ->set('durationName', '2 hours')->set('durationQuantity', 2)->set('durationUnit', 'hours')->call('addDuration')
            ->set('requirementResource', 'pool:'.$pool->id)->set('requirementQuantity', 1)->set('requirementPer', 1)->call('addRequirement')
            ->set('priceName', 'Launch')->set('priceKind', 'base')->set('priceAmount', '25.50')->call('addPrice')
            ->assertHasNoErrors();

        $this->assertSame(120, ServiceDuration::query()->value('duration_minutes'));
        $this->assertSame(1, ResourceRequirement::query()->count());
        $this->assertSame(2550, PriceRule::query()->value('amount_cents'));
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'service.created')->count());
    }

    public function test_gear_at_another_location_cannot_be_linked(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        $other = Location::factory()->create(['tenant_id' => $workspace['tenant']->id]);
        $elsewhere = Pool::factory()->create(['tenant_id' => $workspace['tenant']->id, 'location_id' => $other->id]);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->set('requirementResource', 'pool:'.$elsewhere->id)
            ->call('addRequirement')
            ->assertHasErrors('requirementResource');
    }

    public function test_a_walk_in_is_held_priced_paid_offline_and_confirmed(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(14, 0);

        Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->set('date', $start->toDateString())
            ->set('participants', 2)
            ->set('startsAt', $start->toIso8601String())
            ->set('customerName', 'Ada Lovelace')
            ->set('customerEmail', 'ada@example.com')
            ->call('book')
            ->assertHasNoErrors();

        $booking = Booking::query()->firstOrFail();
        $this->assertSame('held', $booking->status->value);
        $this->assertSame($workspace['user']->id, $booking->created_by);

        Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->set('taxPercent', '6')
            ->call('price')
            ->assertSet('problem', null)
            ->assertSee('Booking fee')
            ->call('recordOffline')
            ->assertSet('problem', null);

        $booking->refresh();
        $this->assertSame('confirmed', $booking->status->value);
        $this->assertSame('offline_paid', $booking->payment_status->value);
    }

    public function test_the_last_kayaks_cannot_be_double_booked_from_the_screen(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 2, 'UTC');
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);

        foreach ([['Ada', null], ['Grace', 'The requested resources are not available.']] as [$name, $expected]) {
            $test = Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
                ->set('date', $start->toDateString())
                ->set('participants', 2)
                ->set('startsAt', $start->toIso8601String())
                ->set('customerName', $name)
                ->call('book');

            if ($expected === null) {
                $test->assertSet('problem', null);
            } else {
                $this->assertNotNull($test->get('problem'));
            }
        }

        $this->assertSame(1, Booking::query()->count());
    }

    public function test_cancel_releases_the_booking_and_is_audited(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 2, 'UTC');
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);
        Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->set('date', $start->toDateString())
            ->set('startsAt', $start->toIso8601String())
            ->set('customerName', 'Ada')
            ->call('book');
        $booking = Booking::query()->firstOrFail();

        Livewire::test(BookingShow::class, ['booking' => $booking->id])->call('cancelBooking');

        $this->assertSame('cancelled', $booking->fresh()?->status->value);
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'booking.cancelled')->count());
    }

    public function test_staff_cannot_change_setup_or_fee_payer(): void
    {
        $workspace = $this->signedIn('staff');
        $shop = $this->rentalShop($workspace['tenant']);

        Livewire::test(Resources::class)
            ->set('locationId', $shop['location']->id)
            ->set('name', 'Sneaky canoe')
            ->call('create')
            ->assertForbidden();

        Livewire::test(Settings::class)
            ->set('feePayer', 'operator_absorbed')
            ->call('save')
            ->assertForbidden();

        $this->assertSame('customer_paid', TenantSetting::query()->value('fee_payer')?->value);
    }

    public function test_the_owner_switches_who_pays_the_booking_fee(): void
    {
        $this->signedIn();

        Livewire::test(Settings::class)
            ->set('feePayer', 'operator_absorbed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('operator_absorbed', TenantSetting::query()->firstOrFail()->fee_payer->value);
    }

    public function test_card_payment_link_explains_when_stripe_is_not_connected(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 2, 'UTC');
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);
        Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->set('date', $start->toDateString())
            ->set('startsAt', $start->toIso8601String())
            ->set('customerName', 'Ada')
            ->call('book');
        $booking = Booking::query()->firstOrFail();

        Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->call('price')
            ->call('createPaymentLink')
            ->assertSet('problem', 'Online payments are not enabled for this workspace.');
    }
}
