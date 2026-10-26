<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Setup\Resources;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Pool;
use App\Models\Refund;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffResourceBufferTest extends TestCase
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

    public function test_an_owner_sets_prep_and_return_minutes_and_the_next_booking_leaves_them_free(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $boat = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Pontoon',
        ]);
        $this->actingAs($alpha['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Prep before Single kayak (minutes)', false)
            ->assertSee('Return after Pontoon (minutes)', false);

        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(Resources::class)
            ->set('kind', 'pool')
            ->set('locationId', $shop['location']->id)
            ->set('name', 'Tandem kayak')
            ->set('quantity', 2)
            ->set('prepMinutes', 10)
            ->set('returnMinutes', 20)
            ->set('turnaround', 5)
            ->call('create')
            ->assertHasNoErrors();

        $tandem = Pool::query()->where('name', 'Tandem kayak')->sole();
        $this->assertSame(10, $tandem->prep_buffer_minutes);
        $this->assertSame(20, $tandem->return_buffer_minutes);
        $this->assertSame(5, $tandem->turnaround_minutes);

        Livewire::test(Resources::class)
            ->call('setBuffer', 'pool', $shop['pool']->id, 'return', 30)
            ->assertHasNoErrors()
            ->call('setBuffer', 'asset', $boat->id, 'prep', 15)
            ->assertHasNoErrors();

        $this->assertSame(30, $shop['pool']->fresh()->return_buffer_minutes);
        $this->assertSame(0, $shop['pool']->fresh()->prep_buffer_minutes);
        $this->assertSame(15, $boat->fresh()->prep_buffer_minutes);
        $audit = AuditLog::query()->where('action', 'pool.updated')->where('subject_id', $shop['pool']->id)->sole();
        $this->assertSame(['return_buffer_minutes' => 30], $audit->properties);

        $allocator = app(Allocator::class);
        $first = $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-06-16 15:00:00', 'first'));
        $this->assertSame('held', $first->status->value);

        try {
            $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-06-16 16:00:00', 'too-soon'));
            $this->fail('A booking was held inside the return buffer.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That time is no longer available.', $exception->getMessage());
        }

        $after = $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-06-16 16:30:00', 'after-buffer'));
        $this->assertSame('held', $after->status->value);
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, PlatformFee::query()->count());
    }

    public function test_a_return_of_zero_still_allows_the_next_booking_to_start_when_the_first_ends(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $this->actingAs($alpha['user']);

        Livewire::test(Resources::class)
            ->call('setBuffer', 'pool', $shop['pool']->id, 'return', 0)
            ->assertHasNoErrors();

        $allocator = app(Allocator::class);
        $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-06-16 15:00:00', 'first'));
        $next = $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-06-16 16:00:00', 'adjacent'));

        $this->assertSame('held', $next->status->value);
    }

    public function test_prep_minutes_block_a_booking_that_runs_into_them(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $this->actingAs($alpha['user']);

        Livewire::test(Resources::class)
            ->call('setBuffer', 'pool', $shop['pool']->id, 'prep', 30)
            ->assertHasNoErrors();

        $allocator = app(Allocator::class);
        $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-06-16 15:00:00', 'later'));

        try {
            $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-06-16 14:15:00', 'into-prep'));
            $this->fail('A booking was held inside the prep buffer.');
        } catch (AllocationUnavailableException $exception) {
            $this->assertSame('That time is no longer available.', $exception->getMessage());
        }

        $clear = $allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-06-16 13:30:00', 'clear'));
        $this->assertSame('held', $clear->status->value);
    }

    public function test_a_buffer_outside_the_range_is_refused_and_the_row_stays(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $shop['pool']->update(['return_buffer_minutes' => 12]);
        $this->actingAs($alpha['user']);

        Livewire::test(Resources::class)
            ->call('setBuffer', 'pool', $shop['pool']->id, 'return', '1441')
            ->assertHasErrors('pool-'.$shop['pool']->id.'-return')
            ->call('setBuffer', 'pool', $shop['pool']->id, 'prep', '-5')
            ->assertHasErrors('pool-'.$shop['pool']->id.'-prep')
            ->call('setBuffer', 'pool', $shop['pool']->id, 'turnaround', '15.5')
            ->assertHasErrors('pool-'.$shop['pool']->id.'-turnaround')
            ->call('setBuffer', 'pool', $shop['pool']->id, 'other', '10')
            ->assertStatus(422);

        $pool = $shop['pool']->fresh();
        $this->assertSame(0, $pool->prep_buffer_minutes);
        $this->assertSame(12, $pool->return_buffer_minutes);
        $this->assertSame(0, $pool->turnaround_minutes);
        $this->assertSame(0, AuditLog::query()->where('action', 'pool.updated')->count());
    }

    public function test_staff_can_see_the_minutes_and_cannot_change_them(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $shop['pool']->update(['prep_buffer_minutes' => 5, 'return_buffer_minutes' => 10]);
        $this->actingAs($alpha['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('5 min before a booking, 10 min after', false)
            ->assertDontSee('Prep before Single kayak (minutes)', false);

        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(Resources::class)
            ->call('setBuffer', 'pool', $shop['pool']->id, 'return', 40)
            ->assertForbidden();

        $this->assertSame(10, $shop['pool']->fresh()->return_buffer_minutes);
    }

    public function test_a_member_limited_to_another_location_cannot_change_the_buffer(): void
    {
        $alpha = $this->workspace('alpha.test', 'manager');
        app(TenantManager::class)->set($alpha['tenant']);
        $north = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $south = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'South dock',
            'timezone' => 'UTC',
        ]);
        $southPool = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South kayaks',
            'return_buffer_minutes' => 8,
        ]);
        $this->limit($alpha['membership'], $north['location']->id);
        $this->actingAs($alpha['user']);

        Livewire::test(Resources::class)
            ->call('setBuffer', 'pool', $southPool->id, 'return', 40)
            ->assertNotFound();

        $this->assertSame(8, $southPool->fresh()->return_buffer_minutes);

        Livewire::test(Resources::class)
            ->call('setBuffer', 'pool', $north['pool']->id, 'return', 25)
            ->assertHasNoErrors();

        $this->assertSame(25, $north['pool']->fresh()->return_buffer_minutes);
    }

    public function test_another_workspace_cannot_change_the_buffer(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $foreign = Pool::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $beta['tenant']->id])->id,
            'name' => 'Beta kayaks',
            'return_buffer_minutes' => 9,
        ]);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        Livewire::test(Resources::class)
            ->call('setBuffer', 'pool', $foreign->id, 'return', 40)
            ->assertNotFound();

        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(9, $foreign->fresh()->return_buffer_minutes);
    }

    private function intent(string $tenantId, string $serviceId, string $start, string $key): AllocationIntent
    {
        $starts = CarbonImmutable::parse($start, 'UTC');

        return new AllocationIntent(
            $tenantId,
            $serviceId,
            $starts,
            $starts->addHour(),
            1,
            $key,
            'Ada Lovelace',
            'ada@example.com',
        );
    }

    private function limit(Membership $membership, string $locationId): void
    {
        $membership->locations_restricted = true;
        $membership->save();
        MembershipLocation::query()->create([
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'location_id' => $locationId,
        ]);
        app(StaffLocationAccess::class)->forget();
    }
}
