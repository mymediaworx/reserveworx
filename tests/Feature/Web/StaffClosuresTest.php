<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Closures;
use App\Livewire\Staff\NewBooking;
use App\Livewire\Staff\Schedule;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\LocationClosure;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Pool;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Models\ResourceRequirement;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Booking\SlotFinder;
use App\Services\Inventory\OpeningHours;
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

class StaffClosuresTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_closed_times_are_unavailable_for_the_public_page_and_staff_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $this->close($alpha['tenant']->id, $shop['location']->id, '2026-07-04', '2026-07-04', 'Holiday');

        app(TenantManager::class)->set($alpha['tenant']);
        $closedHold = function () use ($alpha, $shop): void {
            app(Allocator::class)->hold(new AllocationIntent(
                $alpha['tenant']->id,
                $shop['service']->id,
                CarbonImmutable::parse('2026-07-04 14:00:00', 'UTC'),
                CarbonImmutable::parse('2026-07-04 15:00:00', 'UTC'),
                1,
                'closed-hold',
                'Closed Guest',
                'closed@example.com',
            ));
        };
        try {
            $closedHold();
            $this->fail('A closed time was held.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That time is closed.', $exception->getMessage());
        }

        $open = app(Allocator::class)->hold(new AllocationIntent(
            $alpha['tenant']->id,
            $shop['service']->id,
            CarbonImmutable::parse('2026-07-05 14:00:00', 'UTC'),
            CarbonImmutable::parse('2026-07-05 15:00:00', 'UTC'),
            1,
            'open-hold',
            'Open Guest',
            'open@example.com',
        ));
        $this->assertSame('held', $open->status->value);

        $slots = app(SlotFinder::class)->rentalSlots($shop['service'], '2026-07-04', 60, 1, CarbonImmutable::parse('2026-07-01 15:00:00', 'UTC'));
        $this->assertNotEmpty($slots);
        $this->assertTrue(collect($slots)->every(fn (array $slot): bool => $slot['closed'] && ! $slot['available']));
        $later = app(SlotFinder::class)->rentalSlots($shop['service'], '2026-07-05', 60, 1, CarbonImmutable::parse('2026-07-01 15:00:00', 'UTC'));
        $this->assertTrue(collect($later)->contains(fn (array $slot): bool => $slot['available'] && ! $slot['closed']));
        app(TenantManager::class)->forget();

        $token = app(WidgetTokens::class)->hostedPlain($alpha['tenant']);
        $duration = $shop['service']->durations->first();
        $this->assertNotNull($duration);
        $this->post($this->tenantUrl('alpha.test', '/book/'.$token.'/services/'.$shop['service']->id), [
            'date' => '2026-07-04',
            'start' => '10:00',
            'duration_id' => $duration->id,
            'participants' => 1,
            'customer_name' => 'Public Guest',
            'customer_email' => 'public@example.com',
            'idempotency_key' => 'public-closed',
        ])->assertStatus(409)->assertSee('That time is closed.');

        $this->post($this->tenantUrl('alpha.test', '/book/'.$token.'/services/'.$shop['service']->id), [
            'date' => '2026-07-06',
            'start' => '10:00',
            'duration_id' => $duration->id,
            'participants' => 1,
            'customer_name' => 'Public Guest',
            'customer_email' => 'public@example.com',
            'idempotency_key' => 'public-open',
        ])->assertRedirect();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertNull(Booking::query()->where('idempotency_key', 'public-closed')->first());
        $this->assertNotNull(Booking::query()->where('idempotency_key', 'public-open')->first());

        $this->actingAs($alpha['user']);
        Livewire::test(NewBooking::class)
            ->set('serviceId', $shop['service']->id)
            ->set('date', '2026-07-04')
            ->assertSee('Closed')
            ->assertDontSee('>Open<', false);
    }

    public function test_a_category_or_single_resource_closure_leaves_the_others_open(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $kayaks = ResourceCategory::factory()->create(['tenant_id' => $alpha['tenant']->id, 'name' => 'Kayaks', 'slug' => 'kayaks']);
        $canoes = ResourceCategory::factory()->create(['tenant_id' => $alpha['tenant']->id, 'name' => 'Canoes', 'slug' => 'canoes']);
        $shop['pool']->update(['resource_category_id' => $kayaks->id]);
        $canoe = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'resource_category_id' => $canoes->id,
            'name' => 'Canoe pool',
            'quantity' => 3,
        ]);
        $canoeService = Service::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Canoe rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'service_id' => $canoeService->id,
            'resource_type' => 'pool',
            'resource_id' => $canoe->id,
            'quantity' => 1,
        ]);

        $this->close($alpha['tenant']->id, $shop['location']->id, '2026-07-04', '2026-07-04', 'Kayaks only', [
            'resource_category_id' => $kayaks->id,
        ]);

        $hours = app(OpeningHours::class);
        $start = CarbonImmutable::parse('2026-07-04 14:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-07-04 15:00:00', 'UTC');
        $this->assertFalse($hours->assess($shop['location'], $start, $end, 'pool', $shop['pool']->id)['open']);
        $this->assertTrue($hours->assess($shop['location'], $start, $end, 'pool', $shop['pool']->id)['closed']);
        $this->assertTrue($hours->assess($shop['location'], $start, $end, 'pool', $canoe->id)['open']);
        $this->assertTrue($hours->assess($shop['location'], $start, $end)['open']);

        try {
            app(Allocator::class)->hold($this->intent($alpha['tenant']->id, $shop['service']->id, $start, 'kayak-closed'));
            $this->fail('A closed category was held.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That time is closed.', $exception->getMessage());
        }

        $canoeHold = app(Allocator::class)->hold($this->intent($alpha['tenant']->id, $canoeService->id, $start, 'canoe-open'));
        $this->assertSame('held', $canoeHold->status->value);

        LocationClosure::query()->delete();
        $this->close($alpha['tenant']->id, $shop['location']->id, '2026-07-04', '2026-07-04', 'One pool', [
            'resource_type' => 'pool',
            'resource_id' => $shop['pool']->id,
        ]);
        $this->assertTrue($hours->assess($shop['location'], $start, $end, 'pool', $canoe->id)['open']);
        $this->assertFalse($hours->assess($shop['location'], $start, $end, 'pool', $shop['pool']->id)['open']);
    }

    public function test_daylight_saving_closures_follow_the_location_clock(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $hours = app(OpeningHours::class);

        $this->close($alpha['tenant']->id, $shop['location']->id, '2026-03-08', '2026-03-08', 'Spring clock');
        $springInside = [
            CarbonImmutable::parse('2026-03-08 06:30:00', 'UTC'),
            CarbonImmutable::parse('2026-03-08 07:30:00', 'UTC'),
        ];
        $this->assertFalse($hours->assess($shop['location'], $springInside[0], $springInside[1])['open']);
        $this->assertTrue($hours->assess(
            $shop['location'],
            CarbonImmutable::parse('2026-03-08 03:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-08 04:00:00', 'UTC'),
        )['open']);
        $this->assertTrue($hours->assess(
            $shop['location'],
            CarbonImmutable::parse('2026-03-09 04:00:00', 'UTC'),
            CarbonImmutable::parse('2026-03-09 05:00:00', 'UTC'),
        )['open']);

        LocationClosure::query()->delete();
        $this->close($alpha['tenant']->id, $shop['location']->id, '2026-03-08', '2026-03-08', 'Missing hour', [
            'all_day' => false,
            'starts_local' => '01:30:00',
            'ends_local' => '03:30:00',
        ]);
        $this->assertFalse($hours->assess($shop['location'], $springInside[0], $springInside[1])['open']);
        $this->assertTrue($hours->assess(
            $shop['location'],
            CarbonImmutable::parse('2026-03-08 07:30:00', 'UTC'),
            CarbonImmutable::parse('2026-03-08 08:30:00', 'UTC'),
        )['open']);

        LocationClosure::query()->delete();
        $this->close($alpha['tenant']->id, $shop['location']->id, '2026-11-01', '2026-11-01', 'Repeated hour', [
            'all_day' => false,
            'starts_local' => '01:00:00',
            'ends_local' => '02:00:00',
        ]);
        $this->assertFalse($hours->assess(
            $shop['location'],
            CarbonImmutable::parse('2026-11-01 05:30:00', 'UTC'),
            CarbonImmutable::parse('2026-11-01 06:00:00', 'UTC'),
        )['open']);
        $this->assertFalse($hours->assess(
            $shop['location'],
            CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC'),
            CarbonImmutable::parse('2026-11-01 06:45:00', 'UTC'),
        )['open']);
        $this->assertTrue($hours->assess(
            $shop['location'],
            CarbonImmutable::parse('2026-11-01 07:00:00', 'UTC'),
            CarbonImmutable::parse('2026-11-01 08:00:00', 'UTC'),
        )['open']);

        Carbon::setTestNow(Carbon::parse('2026-03-01 15:00:00', 'UTC'));
        $this->actingAs($alpha['user']);
        $html = $this->get($this->tenantUrl('alpha.test', '/staff/schedule?date=2026-11-01'))->assertOk()->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('data-minutes="1500"', $html);
        $this->assertStringContainsString('data-reason="Repeated hour"', $html);
        $this->assertStringContainsString('data-resource="pool:'.$shop['pool']->id.'"', $html);
        $this->assertStringContainsString('data-left="4.000"', $html);
        $this->assertStringContainsString('data-width="8.000"', $html);
        $this->assertStringContainsString('class="schedule-closure"', $html);
    }

    public function test_an_overlapping_confirmed_booking_is_listed_and_left_in_place(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $start = CarbonImmutable::parse('2026-07-04 14:00:00', 'UTC');
        $confirmed = app(Allocator::class)->confirm(app(Allocator::class)->hold($this->intent(
            $alpha['tenant']->id,
            $shop['service']->id,
            $start,
            'confirmed-overlap',
            'Ada Lovelace',
        )));
        $held = app(Allocator::class)->hold($this->intent(
            $alpha['tenant']->id,
            $shop['service']->id,
            CarbonImmutable::parse('2026-07-04 15:00:00', 'UTC'),
            'held-overlap',
            'Held Guest',
        ));
        $edge = app(Allocator::class)->confirm(app(Allocator::class)->hold($this->intent(
            $alpha['tenant']->id,
            $shop['service']->id,
            CarbonImmutable::parse('2026-07-04 16:00:00', 'UTC'),
            'edge-booking',
            'Edge Guest',
        )));
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($beta['tenant']);
        $betaShop = $this->rentalShop($beta['tenant'], 4, 'America/Chicago');
        $betaBooking = app(Allocator::class)->confirm(app(Allocator::class)->hold($this->intent(
            $beta['tenant']->id,
            $betaShop['service']->id,
            $start,
            'beta-overlap',
            'Beta Guest',
        )));
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user']);
        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Closures::class)
            ->set('locationId', $shop['location']->id)
            ->set('reason', 'Fourth of July')
            ->set('span', 'hours')
            ->set('startsOn', '2026-07-04')
            ->set('endsOn', '2026-07-04')
            ->set('startsLocal', '09:00')
            ->set('endsLocal', '12:00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Nothing was cancelled or refunded.')
            ->assertSee('Ada Lovelace')
            ->assertSee('href="/staff/bookings/'.$confirmed->id.'"', false)
            ->assertDontSee('Held Guest')
            ->assertDontSee('Edge Guest')
            ->assertDontSee('Beta Guest');

        $fresh = Booking::query()->findOrFail($confirmed->id);
        $this->assertSame('confirmed', $fresh->status->value);
        $this->assertSame('held', $held->fresh()->status->value);
        $this->assertSame('confirmed', $edge->fresh()->status->value);
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame('confirmed', $betaBooking->fresh()->status->value);
        $this->assertSame(0, LocationClosure::query()->count());

        $this->actingAs($alpha['user']);
        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Schedule::class)
            ->call('openMove', $confirmed->id, 'pool:'.$shop['pool']->id)
            ->set('moveStart', '2026-07-04T11:00')
            ->call('moveBooking')
            ->assertSee('That time is closed.');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('2026-07-04 14:00:00', $confirmed->fresh()->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_a_yearly_closure_lists_the_same_holiday_next_year(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-12-01 15:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $next = app(Allocator::class)->confirm(app(Allocator::class)->hold($this->intent(
            $alpha['tenant']->id,
            $shop['service']->id,
            CarbonImmutable::parse('2027-12-25 15:00:00', 'UTC'),
            'next-christmas',
            'Next Year',
        )));
        $dayAfter = app(Allocator::class)->confirm(app(Allocator::class)->hold($this->intent(
            $alpha['tenant']->id,
            $shop['service']->id,
            CarbonImmutable::parse('2027-12-26 15:00:00', 'UTC'),
            'day-after',
            'Day After',
        )));

        $this->actingAs($alpha['user']);
        Livewire::test(Closures::class)
            ->set('locationId', $shop['location']->id)
            ->set('reason', 'Christmas')
            ->set('yearly', true)
            ->set('startsOn', '2026-12-25')
            ->set('endsOn', '2026-12-25')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Next Year')
            ->assertSee('href="/staff/bookings/'.$next->id.'"', false)
            ->assertDontSee('Day After');

        $this->assertSame('confirmed', $next->fresh()->status->value);
        $this->assertSame('confirmed', $dayAfter->fresh()->status->value);
        $this->assertSame(0, Refund::query()->count());

        $closure = LocationClosure::query()->firstOrFail();
        $this->assertTrue($closure->recurs_yearly);
        $this->assertFalse(app(OpeningHours::class)->assess(
            $shop['location'],
            CarbonImmutable::parse('2027-12-25 15:00:00', 'UTC'),
            CarbonImmutable::parse('2027-12-25 16:00:00', 'UTC'),
        )['open']);
        $this->assertTrue(app(OpeningHours::class)->assess(
            $shop['location'],
            CarbonImmutable::parse('2027-12-26 15:00:00', 'UTC'),
            CarbonImmutable::parse('2027-12-26 16:00:00', 'UTC'),
        )['open']);
    }

    public function test_settings_permission_and_tenant_isolation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $staffUser = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $staffUser->id,
        ]);
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $closure = $this->close($alpha['tenant']->id, $shop['location']->id, '2026-08-01', '2026-08-01', 'Alpha holiday only');

        $this->actingAs($staffUser);
        $this->get($this->tenantUrl('alpha.test', '/staff/closures'))
            ->assertOk()
            ->assertSee('Alpha holiday only')
            ->assertDontSee('Add a closure');

        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Closures::class)->call('save')->assertForbidden();
        $this->assertSame(1, LocationClosure::withoutGlobalScope(TenantScope::class)->count());

        $frontDesk = Role::query()->create(['slug' => 'front-desk', 'name' => 'Front desk']);
        $frontDesk->permissions()->sync([
            Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id,
        ]);
        $alpha['membership']->update(['role_id' => $frontDesk->id]);
        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/closures'))
            ->assertForbidden();

        $alpha['membership']->update(['role_id' => Role::query()->where('slug', 'owner')->firstOrFail()->id]);
        $this->actingAs($beta['user'])
            ->get($this->tenantUrl('beta.test', '/staff/closures'))
            ->assertOk()
            ->assertDontSee('Alpha holiday only');

        app(TenantManager::class)->set($beta['tenant']);
        Livewire::test(Closures::class)->call('remove', $closure->id)->assertNotFound();
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertNotNull(LocationClosure::query()->find($closure->id));

        $html = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/closures'))
            ->assertOk()
            ->getContent();
        preg_match('/wire:snapshot="([^"]+)"/', (string) $html, $match);
        $this->assertNotEmpty($match);
        $snapshot = html_entity_decode($match[1]);
        $uri = app('livewire')->getUpdateUri();
        $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'remove', 'params' => [$closure->id]]]]]];
        $headers = ['X-Livewire' => '1', 'X-CSRF-TOKEN' => csrf_token()];

        $this->withHeaders($headers)
            ->postJson($this->tenantUrl('beta.test', $uri), $payload)
            ->assertForbidden();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertNotNull(LocationClosure::query()->find($closure->id));

        $this->actingAs($alpha['user']);
        Livewire::test(Closures::class)
            ->set('locationId', $shop['location']->id)
            ->set('scope', 'resource')
            ->set('resourceKey', 'pool:'.$shop['pool']->id)
            ->set('reason', 'One boat')
            ->set('startsOn', '2026-08-02')
            ->set('endsOn', '2026-08-02')
            ->call('save')
            ->assertHasNoErrors();

        $limited = LocationClosure::query()->where('reason', 'One boat')->firstOrFail();
        $this->assertSame('pool', $limited->resource_type);
        $this->assertSame($shop['pool']->id, $limited->resource_id);
        $this->assertNull($limited->resource_category_id);

        Livewire::test(Closures::class)
            ->call('edit', $limited->id)
            ->set('reason', 'One boat, revised')
            ->call('save')
            ->assertHasNoErrors();
        $this->assertSame('One boat, revised', $limited->fresh()->reason);

        Livewire::test(Closures::class)->call('remove', $limited->id);
        $this->assertNull(LocationClosure::query()->find($limited->id));

        $foreign = Asset::factory()->create(['tenant_id' => $beta['tenant']->id]);
        Livewire::test(Closures::class)
            ->set('locationId', $shop['location']->id)
            ->set('scope', 'resource')
            ->set('resourceKey', 'asset:'.$foreign->id)
            ->set('reason', 'Sneaky')
            ->set('startsOn', '2026-08-03')
            ->set('endsOn', '2026-08-03')
            ->call('save')
            ->assertHasErrors(['resource_key']);
        $this->assertNull(LocationClosure::query()->where('reason', 'Sneaky')->first());
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function close(string $tenantId, string $locationId, string $startsOn, string $endsOn, string $reason, array $extra = []): LocationClosure
    {
        return LocationClosure::query()->create(array_merge([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'reason' => $reason,
            'all_day' => true,
            'recurs_yearly' => false,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'starts_local' => null,
            'ends_local' => null,
        ], $extra));
    }

    private function intent(string $tenantId, string $serviceId, CarbonImmutable $start, string $key, string $name = 'Guest'): AllocationIntent
    {
        return new AllocationIntent(
            $tenantId,
            $serviceId,
            $start,
            $start->addHour(),
            1,
            $key,
            $name,
            'guest@example.com',
        );
    }
}
