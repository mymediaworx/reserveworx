<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Calendar;
use App\Livewire\Staff\Schedule;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlatformFee;
use App\Models\Pool;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CalendarPollingTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_calendar_and_schedule_poll_every_minute_without_a_websocket(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->location($alpha['tenant'], 'North dock');
        $this->actingAs($alpha['user']);

        foreach (['day', 'week', 'month', 'agenda'] as $view) {
            $page = $this->get($this->tenantUrl('alpha.test', '/staff/calendar?view='.$view.'&date=2026-06-17'))
                ->assertOk();
            $this->assertPolls($page->getContent());
        }

        foreach (['day', 'week'] as $view) {
            $page = $this->get($this->tenantUrl('alpha.test', '/staff/schedule?view='.$view.'&date=2026-06-17'))
                ->assertOk();
            $this->assertPolls($page->getContent());
        }
    }

    public function test_refresh_shows_a_new_booking_and_does_not_write_money(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-17 12:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $dock = $this->location($alpha['tenant'], 'North dock');
        $betaDock = $this->location($beta['tenant'], 'Beta dock');
        $service = $this->service($alpha['tenant'], $dock, 'Harbor rental');
        $betaService = $this->service($beta['tenant'], $betaDock, 'Beta rental');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        $screen = Livewire::test(Calendar::class)
            ->set('view', 'day')
            ->set('date', '2026-06-17')
            ->assertDontSee('Late Guest');

        $this->booking($alpha, $dock, $service, 'Late Guest', '2026-06-17 15:00:00');
        $money = $this->moneyCounts();
        $ids = Booking::withoutGlobalScopes()->pluck('id')->all();

        $screen->call('refreshCalendar')
            ->assertSee('Late Guest')
            ->assertDontSee('Beta Guest');

        app(TenantManager::class)->set($beta['tenant']);
        $this->booking($beta, $betaDock, $betaService, 'Beta Guest', '2026-06-17 15:00:00');
        app(TenantManager::class)->set($alpha['tenant']);

        $screen->call('refreshCalendar')
            ->assertSee('Late Guest')
            ->assertDontSee('Beta Guest');

        $this->assertSame($money, $this->moneyCounts());
        $this->assertEqualsCanonicalizing($ids, Booking::withoutGlobalScopes()->where('tenant_id', $alpha['tenant']->id)->pluck('id')->all());
    }

    public function test_a_limited_member_does_not_see_another_location_after_refresh(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-17 12:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test', 'staff');
        $north = $this->location($alpha['tenant'], 'North dock');
        $south = $this->location($alpha['tenant'], 'South dock');
        $northService = $this->service($alpha['tenant'], $north, 'North rental');
        $southService = $this->service($alpha['tenant'], $south, 'South rental');
        $alpha['membership']->locations_restricted = true;
        $alpha['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'membership_id' => $alpha['membership']->id,
            'location_id' => $north->id,
        ]);
        app(StaffLocationAccess::class)->forget();
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        $screen = Livewire::test(Calendar::class)->set('view', 'day')->set('date', '2026-06-17');
        $this->booking($alpha, $north, $northService, 'North Guest', '2026-06-17 15:00:00');
        $this->booking($alpha, $south, $southService, 'South Guest', '2026-06-17 15:00:00');

        $screen->call('refreshCalendar')
            ->assertSee('North Guest')
            ->assertDontSee('South Guest');
    }

    public function test_the_schedule_pauses_while_a_panel_is_open_and_then_shows_a_new_hold(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-20 12:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $dock = $this->location($alpha['tenant'], 'North dock');
        $boats = $this->category($alpha['tenant'], 'Boats');
        $skiff = $this->asset($dock, $boats, 'Harbor skiff');
        $service = $this->serviceOn($alpha['tenant'], $dock, $skiff, 'Harbor rental');
        $first = $this->hold($alpha, $service, '2026-07-20 15:00:00', 'First Guest');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        $screen = Livewire::test(Schedule::class)
            ->set('view', 'day')
            ->set('date', '2026-07-20')
            ->assertSee('First Guest')
            ->assertSeeHtml($this->pollAttribute());

        $screen->call('openMove', $first->id, 'asset:'.$skiff->id)
            ->assertDontSeeHtml('wire:poll');
        $screen->call('closeMove')
            ->assertSeeHtml($this->pollAttribute());
        $screen->call('openSlot', 'asset:'.$skiff->id, '2026-07-20', '15:00')
            ->assertDontSeeHtml('wire:poll');
        $screen->call('closeBooking')
            ->assertSeeHtml($this->pollAttribute())
            ->assertDontSee('Second Guest');

        $money = $this->moneyCounts();
        $this->hold($alpha, $service, '2026-07-20 16:00:00', 'Second Guest');
        app(TenantManager::class)->set($alpha['tenant']);

        $screen->call('refreshCalendar')
            ->assertSee('Second Guest')
            ->assertSee('First Guest');

        $betaDock = $this->location($beta['tenant'], 'Beta dock');
        $betaBoat = $this->asset($betaDock, $this->category($beta['tenant'], 'Beta boats'), 'Beta skiff');
        $betaService = $this->serviceOn($beta['tenant'], $betaDock, $betaBoat, 'Beta rental');
        $this->hold($beta, $betaService, '2026-07-20 15:00:00', 'Beta Guest');
        app(TenantManager::class)->set($alpha['tenant']);

        $screen->call('refreshCalendar')
            ->assertSee('Second Guest')
            ->assertDontSee('Beta Guest');
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_someone_who_cannot_view_bookings_does_not_get_the_poll(): void
    {
        $staff = $this->workspace('dock.test', 'staff');
        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $permission = Permission::query()->where('slug', 'bookings.view')->firstOrFail();
        $role->permissions()->detach($permission->id);

        try {
            $this->actingAs($staff['user']);
            $calendar = $this->get($this->tenantUrl('dock.test', '/staff/calendar'))->assertForbidden();
            $schedule = $this->get($this->tenantUrl('dock.test', '/staff/schedule'))->assertForbidden();
            $this->assertStringNotContainsString('wire:poll', (string) $calendar->getContent());
            $this->assertStringNotContainsString('wire:poll', (string) $schedule->getContent());
        } finally {
            $role->permissions()->syncWithoutDetaching([$permission->id]);
        }
    }

    private function assertPolls(string $html): void
    {
        $this->assertStringContainsString($this->pollAttribute(), $html);
        $this->assertStringNotContainsString('wire:poll.60s.keep-alive', $html);
        $this->assertStringNotContainsString('laravel-echo', strtolower($html));
        $this->assertStringNotContainsString('pusher', strtolower($html));
        $this->assertStringNotContainsString('websocket', strtolower($html));
    }

    private function pollAttribute(): string
    {
        return 'wire:poll.60s="refreshCalendar"';
    }

    /**
     * @return array{payments: int, refunds: int, fees: int}
     */
    private function moneyCounts(): array
    {
        return [
            'payments' => Payment::withoutGlobalScopes()->count(),
            'refunds' => Refund::withoutGlobalScopes()->count(),
            'fees' => PlatformFee::withoutGlobalScopes()->count(),
        ];
    }

    private function location(Tenant $tenant, string $name): Location
    {
        return Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => 'UTC',
        ]);
    }

    private function service(Tenant $tenant, Location $location, string $name): Service
    {
        app(TenantManager::class)->set($tenant);

        return Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Rental,
        ]);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function booking(array $workspace, Location $location, Service $service, string $name, string $start): Booking
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
        ]);
        $starts = CarbonImmutable::parse($start, 'UTC');

        return Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $starts->addHour(),
            'participants' => 2,
            'payload_hash' => hash('sha256', $name.$start),
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

    private function serviceOn(Tenant $tenant, Location $location, Asset|Pool $resource, string $name): Service
    {
        $service = $this->service($tenant, $location, $name);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => $resource instanceof Asset ? 'asset' : 'pool',
            'resource_id' => $resource->id,
            'quantity' => 1,
        ]);

        return $service;
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function hold(array $workspace, Service $service, string $start, string $name): Booking
    {
        $starts = CarbonImmutable::parse($start, 'UTC');
        app(TenantManager::class)->set($workspace['tenant']);

        return app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $service->id,
            $starts,
            $starts->addHour(),
            1,
            null,
            $name,
            strtolower(str_replace(' ', '.', $name)).'@example.com',
        ));
    }
}
