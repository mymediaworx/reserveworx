<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Setup\AddOns;
use App\Livewire\Staff\SetupGuidePage;
use App\Models\AddOn;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingAddOn;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Onboarding\SetupGuide;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffAddOnTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_adds_an_extra_and_a_guest_hold_stores_that_catalog_price(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $this->actingAs($alpha['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('/staff/add-ons', false);

        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(AddOns::class)
            ->set('name', 'Dry bag')
            ->set('description', 'Keeps a phone dry')
            ->set('kind', 'addon')
            ->set('pricing', 'per_person')
            ->set('amount', '2.00')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('Dry bag')
            ->assertSee('$2.00');

        $addOn = AddOn::query()->where('name', 'Dry bag')->firstOrFail();
        $this->assertSame('addon', $addOn->kind->value);
        $this->assertSame('per_person', $addOn->pricing->value);
        $this->assertSame(200, $addOn->amount_cents);
        $this->assertTrue($addOn->active);
        $this->assertNull($addOn->max_quantity);

        Livewire::test(AddOns::class)
            ->set('name', 'Dry bag')
            ->set('pricing', 'flat')
            ->set('amount', '1.00')
            ->call('create')
            ->assertHasErrors('name');

        $token = app(WidgetTokens::class)->hostedPlain($alpha['tenant']);
        $duration = $shop['service']->durations->first();
        $this->assertNotNull($duration);
        $page = $this->tenantUrl('alpha.test', '/book/'.$token.'/services/'.$shop['service']->id);
        app(TenantManager::class)->forget();

        $this->get($page.'?date=2026-06-16&duration_id='.$duration->id.'&participants=2')
            ->assertOk()
            ->assertSee('Equipment and add-ons')
            ->assertSee('Dry bag')
            ->assertSee('$2.00 per person');

        $held = $this->post($page, [
            'date' => '2026-06-16',
            'start' => '10:00',
            'duration_id' => $duration->id,
            'participants' => 2,
            'customer_name' => 'Ada Lovelace',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'staff-addon-hold',
            'add_ons' => [$addOn->id => 1],
        ]);
        $held->assertRedirect();
        $bookingId = $this->bookingId((string) $held->headers->get('Location'));

        app(TenantManager::class)->set($alpha['tenant']);
        $line = BookingAddOn::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame($addOn->id, $line->add_on_id);
        $this->assertSame('Dry bag', $line->name);
        $this->assertSame(200, $line->unit_amount_cents);
        $this->assertSame(1, $line->quantity);
        $this->assertSame(400, $line->total_cents);
        $snapshot = PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail();
        $this->assertSame(4_500, $snapshot->payload['services_cents']);
        $this->assertSame(400, $snapshot->payload['add_ons_cents']);
        $this->assertSame(4_900, $snapshot->payload['basis_cents']);
        $this->assertSame(0, Payment::withoutGlobalScopes()->count());
        $this->assertSame(0, Refund::withoutGlobalScopes()->count());
        $this->assertSame(0, PlatformFee::withoutGlobalScopes()->count());

        $this->actingAs($alpha['user']);
        Livewire::test(AddOns::class)
            ->call('edit', $addOn->id)
            ->set('amount', '9.00')
            ->call('save')
            ->assertHasNoErrors();

        $addOn->refresh();
        $line->refresh();
        $this->assertSame(900, $addOn->amount_cents);
        $this->assertSame(400, $line->total_cents);
        $this->assertSame(400, PricingSnapshot::query()->where('booking_id', $bookingId)->firstOrFail()->payload['add_ons_cents']);
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'add_on.created')->count());
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'add_on.updated')->count());
        app(TenantManager::class)->forget();

        $this->get((string) $held->headers->get('Location'))
            ->assertOk()
            ->assertSee('Dry bag')
            ->assertSee('$4.00');
    }

    public function test_staff_can_read_add_ons_but_not_change_them(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        app(TenantManager::class)->set($alpha['tenant']);
        AddOn::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Paddle',
            'kind' => 'equipment',
            'pricing' => 'each',
            'amount_cents' => 500,
            'max_quantity' => 4,
        ]);
        $this->actingAs($alpha['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/add-ons'))
            ->assertOk()
            ->assertSee('Paddle')
            ->assertSee('up to 4')
            ->assertDontSee('Add an extra');

        app(TenantManager::class)->set($alpha['tenant']);
        $id = (string) AddOn::query()->where('name', 'Paddle')->value('id');

        Livewire::test(AddOns::class)
            ->set('name', 'Sneaky')
            ->set('amount', '1.00')
            ->call('create')
            ->assertForbidden();

        Livewire::test(AddOns::class)
            ->call('deactivate', $id)
            ->assertForbidden();

        Livewire::test(AddOns::class)
            ->call('delete', $id)
            ->assertForbidden();

        $this->assertNull(AddOn::query()->where('name', 'Sneaky')->first());
        $this->assertTrue(AddOn::query()->where('name', 'Paddle')->firstOrFail()->active);
    }

    public function test_another_workspace_add_on_is_not_listed(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $hidden = AddOn::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta cooler',
        ]);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/add-ons'))
            ->assertOk()
            ->assertDontSee('Beta cooler');

        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(AddOns::class)
            ->call('edit', $hidden->id)
            ->assertNotFound();
    }

    public function test_an_add_on_on_a_booking_cannot_be_deleted_and_an_inactive_one_is_not_offered(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $addOn = AddOn::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Cooler',
            'pricing' => 'flat',
            'amount_cents' => 1_500,
        ]);
        $start = CarbonImmutable::parse('2026-06-16 14:00:00', 'UTC');
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $alpha['tenant']->id,
            $shop['service']->id,
            $start,
            $start->addHour(),
            1,
            'addon-booked',
            'Ada Lovelace',
            'ada@example.com',
        ));
        BookingAddOn::factory()->create([
            'booking_id' => $booking->id,
            'add_on_id' => $addOn->id,
            'name' => 'Cooler',
        ]);
        $this->actingAs($alpha['user']);

        Livewire::test(AddOns::class)
            ->call('delete', $addOn->id)
            ->assertSee('This add-on is on a booking. Mark it inactive instead.');

        $this->assertNotNull(AddOn::query()->find($addOn->id));

        Livewire::test(AddOns::class)
            ->call('deactivate', $addOn->id)
            ->assertSee('Off');

        $this->assertFalse($addOn->fresh()?->active);
        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'add_on.deleted')->count());

        $token = app(WidgetTokens::class)->hostedPlain($alpha['tenant']);
        app(TenantManager::class)->forget();

        $duration = $shop['service']->durations->first();
        $this->assertNotNull($duration);

        $this->get($this->tenantUrl('alpha.test', '/book/'.$token.'/services/'.$shop['service']->id).'?date=2026-06-17&duration_id='.$duration->id.'&participants=1')
            ->assertOk()
            ->assertSee('Start time')
            ->assertDontSee('Equipment and add-ons')
            ->assertDontSee('Cooler');
    }

    public function test_a_flat_price_rejects_a_maximum_quantity_and_the_page_fits_a_narrow_screen(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/add-ons'))
            ->assertOk()
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('min-height: 44px', false);

        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(AddOns::class)
            ->set('name', 'Paddle')
            ->set('kind', 'equipment')
            ->set('pricing', 'flat')
            ->set('amount', '5.00')
            ->set('maxQuantity', '4')
            ->call('create')
            ->assertHasErrors('maxQuantity');

        Livewire::test(AddOns::class)
            ->set('name', 'Paddle')
            ->set('kind', 'equipment')
            ->set('pricing', 'each')
            ->set('amount', '5.00')
            ->set('maxQuantity', '4')
            ->call('create')
            ->assertHasNoErrors()
            ->assertSee('up to 4');

        $paddle = AddOn::query()->where('name', 'Paddle')->firstOrFail();
        $this->assertSame(4, $paddle->max_quantity);

        Livewire::test(AddOns::class)
            ->call('delete', $paddle->id)
            ->assertSee('Paddle removed.');

        $this->assertNull(AddOn::query()->find($paddle->id));
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'add_on.deleted')->count());
    }

    public function test_the_setup_guide_opens_add_ons(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        $step = collect(app(SetupGuide::class)->steps($alpha['tenant']))->firstWhere('key', 'addons');
        $this->assertIsArray($step);
        $this->assertSame('/staff/add-ons', $step['href']);
        $this->assertSame('Open add-ons', $step['action']);
        $this->assertSame('todo', $step['state']);

        Livewire::test(SetupGuidePage::class)
            ->assertSee('Open add-ons')
            ->assertSee('/staff/add-ons', false)
            ->assertSee('Ask support');

        AddOn::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Dry bag',
        ]);

        $done = collect(app(SetupGuide::class)->steps($alpha['tenant']->fresh()))->firstWhere('key', 'addons');
        $this->assertIsArray($done);
        $this->assertSame('done', $done['state']);
        $this->assertSame(0, Booking::query()->count());
    }

    private function bookingId(string $location): string
    {
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $this->assertNotEmpty($matches[1] ?? null);

        return $matches[1];
    }
}
