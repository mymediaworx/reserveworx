<?php

namespace Tests\Feature\Web;

use App\Domain\Fees\FeeChannel;
use App\Enums\AddOnPricing;
use App\Livewire\Staff\Schedule;
use App\Models\AddOn;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlatformFee;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Role;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Models\ServiceVariantResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Catalog\BookingAddOnLines;
use App\Services\Pricing\BookingPricer;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffScheduleBookingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_empty_slot_opens_only_for_someone_who_can_create_bookings(): void
    {
        $staff = $this->workspace('dock.test', 'staff');
        $dock = $this->location($staff['tenant'], 'Dock', 'UTC');
        $boats = $this->category($staff['tenant'], 'Boats');
        $skiff = $this->asset($dock, $boats, 'Staff skiff');
        $this->service($staff['tenant'], $dock, $skiff, 'Staff rental');

        $this->actingAs($staff['user']);
        $html = (string) $this->schedule('dock.test')->assertOk()->assertSee('Tap an empty hour on a boat or pool to start a booking.')->getContent();
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*class="schedule-slot"[^>]*data-resource="asset:'.preg_quote($skiff->id, '/').'"/s',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*class="schedule-slot"[^>]*data-resource="location:/s', $html);
        $this->assertStringNotContainsString('id="new-booking-panel"', $html);

        $week = (string) $this->schedule('dock.test', ['view' => 'week'])->assertOk()->getContent();
        $this->assertMatchesRegularExpression(
            '/<button\b[^>]*class="schedule-slot"[^>]*data-start="09:00"/s',
            $week,
        );

        $css = (string) file_get_contents(resource_path('ui/app.css'));
        $this->assertMatchesRegularExpression('/\.schedule-grid\s*\{[^}]*min-width:\s*56\.75rem/s', $css);
        $this->assertMatchesRegularExpression('/@media \(max-width: 420px\) \{.*?\.schedule-grid:not\(\.is-week\) \{\s*min-width: 74\.75rem;/s', $css);
        $this->assertMatchesRegularExpression('/\.choice-pick,\s*\.hour-pick \{\s*min-height: 2\.75rem;/s', $css);

        app(TenantManager::class)->set($staff['tenant']);
        $date = CarbonImmutable::now('UTC')->toDateString();
        $panel = Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$skiff->id, $date, '15:00')
            ->assertSee('New booking')
            ->assertSee('id="new-booking-panel"', false)
            ->assertSee('3:00 PM')
            ->assertSee('Staff skiff');
        $rendered = $panel->html();
        $this->assertLessThan(strpos($rendered, 'schedule-scroll'), strpos($rendered, 'id="new-booking-panel"'));

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $permission = Permission::query()->where('slug', 'bookings.manage')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->schedule('dock.test')
            ->assertOk()
            ->assertDontSee('class="schedule-slot"', false)
            ->assertDontSee('Tap an empty hour on a boat or pool to start a booking.');

        app(TenantManager::class)->set($staff['tenant']);
        Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$skiff->id, $date, '15:00')
            ->assertForbidden();
    }

    public function test_a_taken_slot_is_refused_and_does_not_create_a_booking(): void
    {
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'Dock', 'UTC');
        $boats = $this->category($alpha['tenant'], 'Boats');
        $skiff = $this->asset($dock, $boats, 'Skiff');
        $service = $this->service($alpha['tenant'], $dock, $skiff, 'Skiff rental');
        $this->hold($alpha, $service, '2026-07-20 15:00:00', 'First Guest');

        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        Livewire::test(Schedule::class)
            ->set('date', '2026-07-20')
            ->call('openSlot', 'asset:'.$skiff->id, '2026-07-20', '15:00')
            ->set('bookCustomerName', 'Second Guest')
            ->set('bookCustomerEmail', 'second@example.com')
            ->call('holdBooking')
            ->assertSee('That time is no longer available.')
            ->assertSee('id="new-booking-panel"', false);

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(1, Booking::query()->count());
        $this->assertSame($skiff->id, Hold::query()->value('asset_id'));
    }

    public function test_the_schedule_quote_matches_public_checkout_and_pay_at_pickup_drops_the_fee(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));
        [$workspace, $service, $asset] = $this->pricedRental('dock.test');
        $this->actingAsWorkspace($workspace);
        $token = (string) $this->postJson($this->tenantUrl('dock.test', '/api/v1/widget-tokens'), [
            'name' => 'Website',
        ])->assertCreated()->json('data.token');

        $held = $this->post($this->tenantUrl('dock.test', '/book/'.$token.'/services/'.$service->id), [
            'date' => '2026-06-15',
            'start' => '15:00',
            'duration_minutes' => 60,
            'participants' => 1,
            'customer_name' => 'Ada',
            'customer_email' => 'ada@example.com',
            'idempotency_key' => 'widget-staff-1',
        ]);
        $held->assertRedirect();
        $location = $held->headers->get('Location');
        $this->assertIsString($location);
        preg_match('#/checkout/([^?]+)#', $location, $matches);
        $widgetId = $matches[1];

        app(TenantManager::class)->set($workspace['tenant']);
        $widgetPayload = PricingSnapshot::query()->where('booking_id', $widgetId)->firstOrFail()->payload;
        $this->actingAs($workspace['user']);

        $panel = Livewire::test(Schedule::class)
            ->set('date', '2026-06-15')
            ->call('openSlot', 'asset:'.$asset->id, '2026-06-15', '16:00')
            ->assertSee('$103.00')
            ->assertSee('Booking fee')
            ->assertSee('$3.00')
            ->set('bookCustomerName', 'Sam')
            ->set('bookCustomerEmail', 'sam@example.com')
            ->call('sendPaymentLink')
            ->assertSet('bookProblem', 'Online payments are not enabled for this workspace.');

        $staffId = $panel->get('bookId');
        $this->assertNotSame('', $staffId);
        app(TenantManager::class)->set($workspace['tenant']);
        $staffPayload = PricingSnapshot::query()->where('booking_id', $staffId)->firstOrFail()->payload;

        foreach ([
            'customer_total_cents',
            'customer_fee_line_cents',
            'application_fee_cents',
            'charge_cents',
            'basis_cents',
            'tax_cents',
            'fee_cents',
            'services_cents',
            'add_ons_cents',
            'discount_cents',
            'fee_payer',
            'channel',
        ] as $key) {
            $this->assertSame($widgetPayload[$key], $staffPayload[$key], $key);
        }

        $this->assertSame(10_300, $staffPayload['customer_total_cents']);
        $this->assertSame(300, $staffPayload['customer_fee_line_cents']);
        $this->assertSame($asset->id, Hold::query()->where('booking_id', $staffId)->value('asset_id'));

        $panel->call('payAtPickup')->assertRedirect('/staff/bookings/'.$staffId);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($asset->id, Allocation::query()->where('booking_id', $staffId)->value('asset_id'));
        $payment = Payment::query()->where('booking_id', $staffId)->firstOrFail();
        $this->assertSame(10_000, $payment->amount_cents);
        $this->assertSame(0, $payment->fee_cents);
        $this->assertSame('offline', $payment->method);
        $this->assertSame(0, PlatformFee::query()->where('booking_id', $staffId)->count());
        $after = PricingSnapshot::query()->where('booking_id', $staffId)->firstOrFail()->payload;
        $this->assertSame($staffPayload, $after);
        $this->assertSame('offline_paid', Booking::query()->findOrFail($staffId)->payment_status->value);
    }

    public function test_a_variant_and_add_on_match_a_direct_quote(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'));
        [$workspace, $service, $asset] = $this->pricedRental('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Boats',
        ]);
        $asset->resource_category_id = $category->id;
        $asset->save();
        $variant = ServiceVariant::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Half day',
            'duration_minutes' => 60,
            'price_override_cents' => 8_000,
        ]);
        ServiceVariantResource::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_variant_id' => $variant->id,
            'resource_category_id' => $asset->resource_category_id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        $addOn = AddOn::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Cooler',
            'pricing' => AddOnPricing::Flat,
            'amount_cents' => 1_500,
        ]);
        $this->actingAs($workspace['user']);

        $panel = Livewire::test(Schedule::class)
            ->set('date', '2026-06-15')
            ->call('openSlot', 'asset:'.$asset->id, '2026-06-15', '16:00')
            ->call('chooseVariant', $variant->id)
            ->call('toggleAddOn', $addOn->id)
            ->assertSee('$97.85')
            ->assertSee('Cooler')
            ->set('bookCustomerName', 'Sam')
            ->set('bookCustomerEmail', 'sam@example.com')
            ->call('holdBooking')
            ->assertRedirect();

        $staffId = $panel->get('bookId');
        app(TenantManager::class)->set($workspace['tenant']);
        $lines = app(BookingAddOnLines::class)->price(1, [['id' => $addOn->id, 'quantity' => 1]]);
        $start = CarbonImmutable::parse('2026-06-15 18:00:00', 'UTC');
        $manual = app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $service->id,
            $start,
            $start->addHour(),
            1,
            'manual-variant',
            'Sam',
            'sam-manual@example.com',
            null,
            null,
            null,
            $lines,
            $variant->id,
        ));
        $manualPayload = app(BookingPricer::class)->quote(
            $manual->load('location'),
            $workspace['tenant'],
            FeeChannel::Direct,
            0,
            null,
            100,
        )->payload;
        $staffPayload = PricingSnapshot::query()->where('booking_id', $staffId)->firstOrFail()->payload;

        foreach ([
            'customer_total_cents',
            'customer_fee_line_cents',
            'application_fee_cents',
            'charge_cents',
            'basis_cents',
            'tax_cents',
            'fee_cents',
            'services_cents',
            'add_ons_cents',
            'discount_cents',
            'fee_payer',
            'channel',
            'variant_id',
            'price_override_cents',
        ] as $key) {
            $this->assertSame($manualPayload[$key], $staffPayload[$key], $key);
        }

        $this->assertSame(8_000, $staffPayload['services_cents']);
        $this->assertSame(1_500, $staffPayload['add_ons_cents']);
        $this->assertSame(285, $staffPayload['customer_fee_line_cents']);
        $this->assertSame(9_785, $staffPayload['customer_total_cents']);
        $this->assertSame($asset->id, Hold::query()->where('booking_id', $staffId)->value('asset_id'));
        $this->assertSame($variant->id, Booking::query()->findOrFail($staffId)->service_variant_id);
    }

    public function test_a_slot_booking_cannot_reach_another_workspace(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $dock = $this->location($alpha['tenant'], 'Alpha dock', 'UTC');
        $boat = $this->asset($dock, $this->category($alpha['tenant'], 'Boats'), 'Alpha skiff');
        $service = $this->service($alpha['tenant'], $dock, $boat, 'Alpha rental');
        app(TenantManager::class)->set($alpha['tenant']);
        PriceRule::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'service_id' => $service->id,
            'name' => 'Launch',
            'kind' => 'base',
            'amount_cents' => 2500,
            'window' => 'always',
        ]);
        app(TenantManager::class)->forget();
        $betaDock = $this->location($beta['tenant'], 'Beta dock', 'UTC');
        $betaBoat = $this->asset($betaDock, $this->category($beta['tenant'], 'Beta boats'), 'Beta boat');
        $betaService = $this->service($beta['tenant'], $betaDock, $betaBoat, 'Beta rental');
        app(TenantManager::class)->set($alpha['tenant']);
        $customer = Customer::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'No Email',
            'email' => null,
        ]);
        app(TenantManager::class)->set($beta['tenant']);
        $betaCustomer = Customer::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta Person',
            'email' => 'beta@example.com',
        ]);

        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$betaBoat->id, '2026-07-20', '15:00')
            ->assertNotFound();

        Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$boat->id, '2026-07-20', '15:00')
            ->call('chooseService', $betaService->id)
            ->assertNotFound();

        Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$boat->id, '2026-07-20', '15:00')
            ->call('selectCustomer', $betaCustomer->id)
            ->assertNotFound();

        $held = Livewire::test(Schedule::class)
            ->call('openSlot', 'asset:'.$boat->id, '2026-07-20', '15:00')
            ->call('selectCustomer', $customer->id)
            ->call('holdBooking')
            ->assertRedirect();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(1, Customer::query()->count());
        $booking = Booking::query()->firstOrFail();
        $this->assertSame($customer->id, $booking->customer_id);
        $this->assertSame($held->get('bookId'), $booking->id);
        $this->assertSame($boat->id, Hold::query()->where('booking_id', $booking->id)->value('asset_id'));
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(0, Booking::query()->count());

        $html = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/schedule?date=2026-07-20'))
            ->assertOk()
            ->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $match);
        $this->assertNotEmpty($match);
        $snapshot = html_entity_decode($match[1]);
        $uri = app('livewire')->getUpdateUri();
        $payload = ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [[
                'path' => '',
                'method' => 'openSlot',
                'params' => ['asset:'.$boat->id, '2026-07-20', '16:00'],
            ]],
        ]]];
        $headers = ['X-Livewire' => '1', 'X-CSRF-TOKEN' => csrf_token()];

        $this->actingAs($alpha['user'])
            ->withHeaders($headers)
            ->postJson($this->tenantUrl('beta.test', $uri), $payload)
            ->assertForbidden();

        $this->actingAs($beta['user'])
            ->withHeaders($headers)
            ->postJson($this->tenantUrl('beta.test', $uri), $payload)
            ->assertNotFound();

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(0, Booking::query()->count());
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(1, Booking::query()->count());

        $this->actingAs($alpha['user'])
            ->withHeaders($headers)
            ->postJson($this->tenantUrl('alpha.test', $uri), $payload)
            ->assertOk();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(1, Booking::query()->count());
    }

    /**
     * @param  array<string, string>  $query
     */
    private function schedule(string $host, array $query = []): TestResponse
    {
        $path = '/staff/schedule';

        if ($query !== []) {
            $path .= '?'.http_build_query($query);
        }

        return $this->get($this->tenantUrl($host, $path));
    }

    private function location(Tenant $tenant, string $name, string $timezone): Location
    {
        return Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    private function category(Tenant $tenant, string $name): ResourceCategory
    {
        return ResourceCategory::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
        ]);
    }

    private function asset(Location $location, ResourceCategory $category, string $name): Asset
    {
        return Asset::factory()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name,
        ]);
    }

    private function service(Tenant $tenant, Location $location, Asset|Pool $resource, string $name): Service
    {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => $resource instanceof Asset ? 'asset' : 'pool',
            'resource_id' => $resource->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();

        return $service;
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function hold(array $workspace, Service $service, string $start, string $name): Booking
    {
        $starts = CarbonImmutable::parse($start, 'UTC');
        app(TenantManager::class)->set($workspace['tenant']);
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $service->id,
            $starts,
            $starts->addHour(),
            1,
            null,
            $name,
            strtolower(str_replace(' ', '.', $name)).'@example.com',
        ));
        app(TenantManager::class)->forget();

        return $booking;
    }

    /**
     * @return array{0: array{tenant: Tenant, user: User}, 1: Service, 2: Asset}
     */
    private function pricedRental(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
            'name' => 'Harbor',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Boat A',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);
        app(TenantManager::class)->forget();
        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl($host, '/api/v1/services/'.$service->id.'/prices'), [
            'name' => 'Weekday',
            'kind' => 'base',
            'amount_cents' => 10_000,
            'window' => 'weekday',
        ])->assertCreated();

        return [$workspace, $service, $asset];
    }
}
