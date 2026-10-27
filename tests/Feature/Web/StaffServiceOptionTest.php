<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\WaitlistStatus;
use App\Livewire\Staff\Schedule;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Models\ServiceVariant;
use App\Models\ServiceVariantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffServiceOptionTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_adds_an_option_and_the_schedule_offers_it(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Tandem kayaks',
            'slug' => 'tandem-kayaks',
        ]);
        $tandem = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'resource_category_id' => $category->id,
            'name' => 'Tandem kayak',
            'quantity' => 3,
        ]);
        $prices = PriceRule::query()->count();

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->set('variantName', 'Sunset tandem')
            ->set('variantQuantity', 1)
            ->set('variantUnit', 'hours')
            ->set('variantPrice', '80.00')
            ->set('variantResource', 'pool:'.$tandem->id)
            ->set('variantGearQuantity', 1)
            ->set('variantCapacity', '2')
            ->call('saveVariant')
            ->assertHasNoErrors()
            ->assertSee('Sunset tandem')
            ->assertSee('1 hour')
            ->assertSee('$80.00')
            ->assertSee('Tandem kayak')
            ->assertSee('Add option');

        $variant = ServiceVariant::query()->where('name', 'Sunset tandem')->firstOrFail();
        $this->assertSame($shop['service']->id, $variant->service_id);
        $this->assertSame(60, $variant->duration_minutes);
        $this->assertSame(8_000, $variant->price_override_cents);
        $link = ServiceVariantResource::query()->where('service_variant_id', $variant->id)->firstOrFail();
        $this->assertSame('pool', $link->resource_type->value);
        $this->assertSame($tandem->id, $link->resource_id);
        $this->assertSame($category->id, $link->resource_category_id);
        $this->assertSame(1, $link->quantity);
        $this->assertSame(2, $link->capacity);
        $this->assertSame($prices, PriceRule::query()->count());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'service_variant.created')->where('subject_id', $variant->id)->exists());

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->set('variantName', 'Sunset tandem')
            ->set('variantQuantity', 2)
            ->set('variantUnit', 'hours')
            ->call('saveVariant')
            ->assertHasErrors('variantName');
        $this->assertSame(1, ServiceVariant::query()->count());

        Livewire::test(Schedule::class)
            ->call('openSlot', 'pool:'.$tandem->id, '2026-06-16', '10:00')
            ->assertSee('Sunset tandem')
            ->assertSee('New booking');

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/services/'.$shop['service']->id))
            ->assertOk()
            ->assertSee('Options')
            ->assertSee('Sunset tandem')
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('grid-template-columns: minmax(0, 1fr)', false)
            ->assertSee('overflow-wrap: anywhere', false);
    }

    public function test_an_owner_can_change_an_option_and_cannot_remove_one_that_is_booked(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        $variant = $this->option($shop['service']->id, $workspace['tenant']->id);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->call('editVariant', $variant->id)
            ->assertSet('variantName', 'Sunset tandem')
            ->assertSet('variantPrice', '80.00')
            ->set('variantName', 'Evening tandem')
            ->set('variantPrice', '')
            ->set('variantResource', '')
            ->call('saveVariant')
            ->assertHasNoErrors()
            ->assertSee('Evening tandem')
            ->assertSee('Service price')
            ->assertDontSee('$80.00');

        $variant->refresh();
        $this->assertSame('Evening tandem', $variant->name);
        $this->assertNull($variant->price_override_cents);
        $this->assertSame(0, ServiceVariantResource::query()->where('service_variant_id', $variant->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'service_variant.updated')->where('subject_id', $variant->id)->exists());

        $starts = CarbonImmutable::parse('2026-06-20 15:00:00', 'UTC');
        $booking = Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'service_id' => $shop['service']->id,
            'service_variant_id' => $variant->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
            'participants' => 2,
            'payload_hash' => hash('sha256', 'sunset'),
        ]);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->call('deleteVariant', $variant->id)
            ->assertSee('This option is on a booking');
        $this->assertNotNull(ServiceVariant::query()->find($variant->id));

        $booking->delete();
        $entry = WaitlistEntry::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $shop['service']->id,
            'service_variant_id' => $variant->id,
            'location_id' => $shop['location']->id,
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
            'participants' => 2,
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.com',
            'status' => WaitlistStatus::Waiting,
        ]);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->call('deleteVariant', $variant->id)
            ->assertSee('This option is on a booking');
        $this->assertNotNull(ServiceVariant::query()->find($variant->id));

        $entry->delete();

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->call('deleteVariant', $variant->id)
            ->assertSee('Evening tandem removed.');
        $this->assertNull(ServiceVariant::query()->find($variant->id));
        $this->assertTrue(AuditLog::query()->where('action', 'service_variant.deleted')->exists());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_staff_can_see_an_option_and_cannot_change_it(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        $this->option($shop['service']->id, $workspace['tenant']->id);
        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);
        $this->actingAs($staff);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->assertSee('Sunset tandem')
            ->assertDontSee('Add option')
            ->set('variantName', 'Staff tandem')
            ->call('saveVariant')
            ->assertForbidden();

        $this->assertSame(0, ServiceVariant::query()->where('name', 'Staff tandem')->count());
    }

    public function test_another_workspace_and_a_limited_member_cannot_change_the_option(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant']);
        $this->option($shop['service']->id, $workspace['tenant']->id);
        $elsewhere = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South dock',
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South kayaks',
            'slug' => 'south-kayaks',
        ]);
        $south = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $elsewhere->id,
            'resource_category_id' => $category->id,
            'name' => 'South tandem',
            'quantity' => 2,
        ]);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->set('variantName', 'Wrong dock')
            ->set('variantQuantity', 1)
            ->set('variantUnit', 'hours')
            ->set('variantResource', 'pool:'.$south->id)
            ->call('saveVariant')
            ->assertHasErrors('variantResource');

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->set('variantName', 'No type')
            ->set('variantQuantity', 1)
            ->set('variantUnit', 'hours')
            ->set('variantResource', 'pool:'.$shop['pool']->id)
            ->call('saveVariant')
            ->assertHasErrors('variantResource')
            ->assertSee('That gear needs an asset type');

        $assetCategory = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Singles',
            'slug' => 'singles',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $shop['location']->id,
            'resource_category_id' => $assetCategory->id,
            'name' => 'Single 1',
            'capacity' => 1,
        ]);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->set('variantName', 'Wrong capacity')
            ->set('variantQuantity', 1)
            ->set('variantUnit', 'hours')
            ->set('variantResource', 'asset:'.$asset->id)
            ->set('variantGearQuantity', 1)
            ->set('variantCapacity', '2')
            ->call('saveVariant')
            ->assertHasErrors('variantResource');

        $this->assertSame(1, ServiceVariant::withoutGlobalScope(TenantScope::class)->count());

        $workspace['membership']->locations_restricted = true;
        $workspace['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'membership_id' => $workspace['membership']->id,
            'location_id' => $elsewhere->id,
        ]);
        app(StaffLocationAccess::class)->forget();

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->assertNotFound();

        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);

        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->assertNotFound();
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(): array
    {
        $workspace = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function option(string $serviceId, string $tenantId): ServiceVariant
    {
        $variant = ServiceVariant::query()->create([
            'tenant_id' => $tenantId,
            'service_id' => $serviceId,
            'name' => 'Sunset tandem',
            'duration_minutes' => 60,
            'price_override_cents' => 8_000,
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $tenantId,
            'name' => 'Tandems',
            'slug' => 'tandems-'.$variant->id,
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $tenantId,
            'location_id' => $variant->service()->value('location_id'),
            'resource_category_id' => $category->id,
            'name' => 'Tandem kayak',
            'quantity' => 2,
        ]);
        ServiceVariantResource::query()->create([
            'tenant_id' => $tenantId,
            'service_variant_id' => $variant->id,
            'resource_category_id' => $category->id,
            'resource_type' => 'pool',
            'resource_id' => $pool->id,
            'quantity' => 1,
            'capacity' => 2,
        ]);

        $fresh = $variant->fresh('resources');
        $this->assertInstanceOf(ServiceVariant::class, $fresh);

        return $fresh;
    }
}
