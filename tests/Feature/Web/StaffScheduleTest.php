<?php

namespace Tests\Feature\Web;

use App\Models\Asset;
use App\Models\Booking;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Permission;
use App\Models\Pool;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffScheduleTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_schedule_stays_inside_the_workspace_location_and_category(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $north = $this->location($alpha['tenant'], 'North dock', 'UTC');
        $south = $this->location($alpha['tenant'], 'South dock', 'UTC');
        $kayaks = $this->category($alpha['tenant'], 'Kayaks');
        $canoes = $this->category($alpha['tenant'], 'Canoes');
        $harbor = $this->asset($north, $kayaks, 'Harbor kayak');
        $spare = $this->pool($north, $kayaks, 'Spare kayaks');
        $canoe = $this->asset($south, $canoes, 'River canoe');
        $betaDock = $this->location($beta['tenant'], 'Beta dock', 'UTC');
        $betaBoat = $this->asset($betaDock, $this->category($beta['tenant'], 'Beta boats'), 'Beta boat');

        $northBooking = $this->hold($alpha, $this->service($alpha['tenant'], $north, $harbor, 'Harbor rental'), '2026-07-20 15:00:00', 'North Guest');
        $poolBooking = $this->hold($alpha, $this->service($alpha['tenant'], $north, $spare, 'Pool rental'), '2026-07-20 18:00:00', 'Pool Guest');
        $southBooking = $this->hold($alpha, $this->service($alpha['tenant'], $south, $canoe, 'Canoe rental'), '2026-07-20 15:00:00', 'South Guest');
        app(TenantManager::class)->set($alpha['tenant']);
        app(Allocator::class)->confirm($southBooking);
        app(TenantManager::class)->forget();
        $later = $this->hold($alpha, $this->service($alpha['tenant'], $north, $harbor, 'Later rental'), '2026-07-21 15:00:00', 'Later Guest');
        $expired = $this->hold($alpha, $this->service($alpha['tenant'], $north, $spare, 'Expired rental'), '2026-07-20 20:00:00', 'Expired Guest');
        app(TenantManager::class)->set($alpha['tenant']);
        Hold::query()->where('booking_id', $expired->id)->update(['expires_at' => now()->subMinute()]);
        app(TenantManager::class)->forget();
        $betaBooking = $this->hold($beta, $this->service($beta['tenant'], $betaDock, $betaBoat, 'Beta rental'), '2026-07-20 15:00:00', 'Beta Guest');

        $this->actingAs($alpha['user']);
        $page = $this->schedule('alpha.test', ['date' => '2026-07-20']);
        $page->assertOk()
            ->assertSee('Schedule')
            ->assertSee('href="/staff/schedule"', false)
            ->assertSee('North Guest')
            ->assertSee('Pool Guest')
            ->assertSee('South Guest')
            ->assertSee('Harbor kayak')
            ->assertSee('Spare kayaks')
            ->assertSee('River canoe')
            ->assertSee('All bookings')
            ->assertDontSee('Beta Guest')
            ->assertDontSee('Beta boat')
            ->assertDontSee('Later Guest')
            ->assertDontSee('Expired Guest');

        $html = (string) $page->getContent();
        $this->assertStringContainsString('class="schedule-scroll"', $html);
        $this->assertSeeInOrder($html, [
            'schedule-category">Kayaks',
            'Harbor kayak',
            'Spare kayaks',
            'schedule-category">Canoes',
            'River canoe',
        ]);
        $this->assertBlock($html, $northBooking->id, 'asset:'.$harbor->id, ['data-kind' => 'hold']);
        $this->assertBlock($html, $northBooking->id, 'location:'.$north->id, ['data-kind' => 'hold']);
        $this->assertBlock($html, $poolBooking->id, 'pool:'.$spare->id, ['data-kind' => 'hold']);
        $this->assertBlock($html, $southBooking->id, 'asset:'.$canoe->id, ['data-kind' => 'allocation']);
        $this->assertStringContainsString('href="/staff/bookings/'.$northBooking->id.'"', $html);
        $this->assertStringNotContainsString('data-booking="'.$later->id.'"', $html);
        $this->assertStringNotContainsString('data-booking="'.$expired->id.'"', $html);
        $this->assertStringNotContainsString('data-booking="'.$betaBooking->id.'"', $html);

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$northBooking->id))
            ->assertOk()
            ->assertSee('North Guest');
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('href="/staff/schedule"', false);

        $southOnly = (string) $this->schedule('alpha.test', [
            'date' => '2026-07-20',
            'location' => $south->id,
        ])->assertOk()->assertDontSee('North Guest')->assertDontSee('Harbor kayak')->assertSee('South Guest')->getContent();
        $this->assertStringNotContainsString('data-booking="'.$northBooking->id.'"', $southOnly);

        $kayaksOnly = (string) $this->schedule('alpha.test', [
            'date' => '2026-07-20',
            'category' => $kayaks->id,
        ])->assertOk()->assertSee('Harbor kayak')->assertDontSee('River canoe')->assertDontSee('South Guest')->getContent();
        $this->assertStringNotContainsString('data-booking="'.$southBooking->id.'"', $kayaksOnly);

        $this->schedule('alpha.test', [
            'date' => '2026-07-20',
            'location' => $betaDock->id,
        ])->assertOk()
            ->assertSee('That location is not in this workspace.')
            ->assertDontSee('Beta Guest')
            ->assertDontSee('North Guest');

        $this->schedule('alpha.test', [
            'date' => '2026-07-20',
            'category' => $betaBoat->resource_category_id,
        ])->assertOk()
            ->assertSee('Nothing in this category.')
            ->assertDontSee('Harbor kayak')
            ->assertDontSee('Beta boat');

        $css = (string) file_get_contents(resource_path('ui/app.css'));
        $this->assertMatchesRegularExpression('/\.schedule-scroll\s*\{[^}]*overflow-x:\s*auto/s', $css);
        $this->assertMatchesRegularExpression('/\.schedule-grid\s*\{[^}]*min-width:\s*56\.75rem/s', $css);
    }

    public function test_blocks_are_placed_in_each_locations_local_time_with_buffers(): void
    {
        $alpha = $this->workspace('alpha.test');
        $east = $this->location($alpha['tenant'], 'East river', 'America/New_York');
        $island = $this->location($alpha['tenant'], 'Island dock', 'Pacific/Auckland');
        $boats = $this->category($alpha['tenant'], 'Boats');
        $eastBoat = $this->asset($east, $boats, 'East skiff', 30, 15);
        $islandBoat = $this->asset($island, $boats, 'Island skiff');
        $eastBooking = $this->hold($alpha, $this->service($alpha['tenant'], $east, $eastBoat, 'East rental'), '2026-06-15 18:00:00', 'East Guest', '2026-06-15 19:00:00');
        $islandBooking = $this->hold($alpha, $this->service($alpha['tenant'], $island, $islandBoat, 'Island rental'), '2026-06-15 18:00:00', 'Island Guest', '2026-06-15 19:00:00');

        $this->actingAs($alpha['user']);
        $june15 = (string) $this->schedule('alpha.test', ['view' => 'day', 'date' => '2026-06-15'])->assertOk()->assertSee('East Guest')->assertDontSee('Island Guest')->getContent();
        $this->assertBlock($june15, $eastBooking->id, 'asset:'.$eastBoat->id, [
            'data-day' => '2026-06-15',
            'data-local-start' => '2026-06-15T14:00',
            'data-local-end' => '2026-06-15T15:00',
            'data-occupied-start' => '2026-06-15T13:30',
            'data-occupied-end' => '2026-06-15T15:15',
            'data-left' => '56.250',
            'data-width' => '7.292',
            'data-buffer-before' => '28.571',
            'data-buffer-after' => '14.286',
        ]);
        $this->assertMatchesRegularExpression('/data-booking="'.$eastBooking->id.'".*?schedule-buffer/s', $june15);
        $this->assertStringNotContainsString('data-booking="'.$islandBooking->id.'"', $june15);

        $june16 = (string) $this->schedule('alpha.test', ['view' => 'day', 'date' => '2026-06-16'])->assertOk()->assertSee('Island Guest')->assertDontSee('East Guest')->getContent();
        $this->assertBlock($june16, $islandBooking->id, 'asset:'.$islandBoat->id, [
            'data-day' => '2026-06-16',
            'data-local-start' => '2026-06-16T06:00',
            'data-local-end' => '2026-06-16T07:00',
            'data-left' => '25.000',
            'data-width' => '4.167',
            'data-buffer-before' => '0.000',
            'data-buffer-after' => '0.000',
        ]);
        preg_match('/data-booking="'.$islandBooking->id.'".*?<\/a>/s', $june16, $islandBlock);
        $this->assertArrayHasKey(0, $islandBlock);
        $this->assertStringNotContainsString('schedule-buffer', $islandBlock[0]);

        $week = (string) $this->schedule('alpha.test', ['view' => 'week', 'date' => '2026-06-15'])->assertOk()->getContent();
        $this->assertBlock($week, $eastBooking->id, 'asset:'.$eastBoat->id, [
            'data-day' => '2026-06-15',
            'data-left' => '56.250',
        ]);
        $this->assertBlock($week, $islandBooking->id, 'location:'.$island->id, [
            'data-day' => '2026-06-16',
            'data-left' => '25.000',
            'data-local-start' => '2026-06-16T06:00',
        ]);
    }

    public function test_daylight_saving_days_use_the_real_length_of_the_local_day(): void
    {
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'Hudson dock', 'America/New_York');
        $boat = $this->asset($dock, $this->category($alpha['tenant'], 'Boats'), 'Hudson skiff');
        $service = $this->service($alpha['tenant'], $dock, $boat, 'Hudson rental');
        $spring = $this->hold($alpha, $service, '2026-03-08 12:00:00', 'Spring Guest', '2026-03-08 13:00:00');
        $fall = $this->hold($alpha, $service, '2026-11-01 15:00:00', 'Fall Guest', '2026-11-01 16:00:00');

        $this->actingAs($alpha['user']);
        $springPage = (string) $this->schedule('alpha.test', ['view' => 'day', 'date' => '2026-03-08'])->assertOk()->getContent();
        $this->assertStringContainsString('data-minutes="1380"', $springPage);
        $this->assertBlock($springPage, $spring->id, 'asset:'.$boat->id, [
            'data-local-start' => '2026-03-08T08:00',
            'data-local-end' => '2026-03-08T09:00',
            'data-left' => '30.435',
            'data-width' => '4.348',
        ]);

        $fallPage = (string) $this->schedule('alpha.test', ['view' => 'day', 'date' => '2026-11-01'])->assertOk()->getContent();
        $this->assertStringContainsString('data-minutes="1500"', $fallPage);
        $this->assertBlock($fallPage, $fall->id, 'asset:'.$boat->id, [
            'data-local-start' => '2026-11-01T10:00',
            'data-local-end' => '2026-11-01T11:00',
            'data-left' => '44.000',
            'data-width' => '4.000',
        ]);
    }

    public function test_booking_access_is_required(): void
    {
        $staff = $this->workspace('dock.test', 'staff');

        $this->get($this->tenantUrl('dock.test', '/staff/schedule'))
            ->assertRedirect('/staff/login');

        $this->actingAs($staff['user'])
            ->get($this->tenantUrl('dock.test', '/staff/schedule'))
            ->assertOk()
            ->assertSee('Schedule')
            ->assertSee('Add a location to build the schedule.');

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $permission = Permission::query()->where('slug', 'bookings.view')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->get($this->tenantUrl('dock.test', '/staff/schedule'))
            ->assertForbidden();
    }

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

    private function asset(Location $location, ResourceCategory $category, string $name, int $prep = 0, int $return = 0): Asset
    {
        return Asset::factory()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name,
            'prep_buffer_minutes' => $prep,
            'return_buffer_minutes' => $return,
        ]);
    }

    private function pool(Location $location, ResourceCategory $category, string $name): Pool
    {
        return Pool::factory()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'resource_category_id' => $category->id,
            'name' => $name,
            'quantity' => 6,
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

    private function hold(array $workspace, Service $service, string $start, string $name, ?string $end = null): Booking
    {
        $starts = CarbonImmutable::parse($start, 'UTC');
        app(TenantManager::class)->set($workspace['tenant']);
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $service->id,
            $starts,
            CarbonImmutable::parse($end ?? $starts->addHour()->utc()->format('Y-m-d H:i:s'), 'UTC'),
            1,
            null,
            $name,
            strtolower(str_replace(' ', '.', $name)).'@example.com',
        ));
        app(TenantManager::class)->forget();

        return $booking;
    }

    /**
     * @param  array<string, string>  $expect
     */
    private function assertBlock(string $html, string $bookingId, string $row, array $expect): void
    {
        preg_match_all('/<a\b[^>]*data-booking="'.preg_quote($bookingId, '/').'"[^>]*>/', $html, $matches);
        $this->assertNotEmpty($matches[0], 'Missing a block for '.$bookingId);
        $found = false;

        foreach ($matches[0] as $tag) {
            if (! str_contains($tag, 'data-row="'.$row.'"')) {
                continue;
            }

            $found = true;

            foreach ($expect as $attribute => $value) {
                $this->assertStringContainsString($attribute.'="'.$value.'"', $tag, $attribute.' on '.$row);
            }
        }

        $this->assertTrue($found, 'Missing '.$row.' for '.$bookingId);
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertSeeInOrder(string $html, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = strpos($html, $needle, $offset);
            $this->assertNotFalse($position, 'Missing '.$needle.' after the previous marker.');
            $offset = $position + strlen($needle);
        }
    }
}
