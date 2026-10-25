<?php

namespace Tests\Feature\Web;

use App\Enums\ResourceStatus;
use App\Enums\WaitlistStatus;
use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\Closures;
use App\Livewire\Staff\Setup\PromoCodes;
use App\Livewire\Staff\Setup\Resources;
use App\Livewire\Staff\Waitlist as WaitlistPage;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\LocationClosure;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\NotificationHook;
use App\Models\Pool;
use App\Models\PromoCode;
use App\Models\Refund;
use App\Models\StaffUndoAction;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Booking\Waitlist;
use App\Services\Inventory\OpeningHours;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffUndoTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_booking_cancel_waits_to_notify_and_undo_restores_it(): void
    {
        $alpha = $this->signedIn();
        $shop = $this->rentalShop($alpha['tenant'], 2, 'UTC');
        $booking = $this->hold($alpha['tenant']->id, $shop['service']->id, 'Ada');

        Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->call('cancelBooking')
            ->assertSee('Booking cancelled.')
            ->assertSee('Undo')
            ->assertSee('@media (max-width: 390px)', false);

        $booking->refresh();
        $this->assertSame('held', $booking->status->value);
        $this->assertSame(1, Hold::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.cancelled')->count());
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'booking.cancelled')->count());

        Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->call('undoAction', (string) StaffUndoAction::query()->value('id'))
            ->assertSee('Undone.');

        $booking->refresh();
        $this->assertSame('held', $booking->status->value);
        $this->assertSame(1, Hold::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.cancelled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.cancel_undone')->count());
    }

    public function test_a_booking_with_a_refund_cancels_immediately(): void
    {
        $alpha = $this->signedIn();
        $shop = $this->rentalShop($alpha['tenant'], 2, 'UTC');
        $booking = $this->hold($alpha['tenant']->id, $shop['service']->id, 'Ada');
        Refund::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $booking->id,
            'reason' => 'operator_cancelled',
            'rules_version' => 'test',
            'customer_refund_cents' => 100,
            'customer_fee_refund_cents' => 0,
            'application_fee_refund_cents' => 0,
            'refund_subtotal_cents' => 100,
            'refund_tax_cents' => 0,
        ]);

        Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->call('cancelBooking')
            ->assertDontSee('undo-toast', false);

        $this->assertSame('cancelled', $booking->fresh()?->status->value);
        $this->assertSame(0, Hold::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.cancelled')->count());
        $this->assertSame(0, StaffUndoAction::query()->count());
    }

    public function test_an_expired_cancel_cannot_be_undone_and_then_notifies(): void
    {
        $alpha = $this->signedIn();
        $shop = $this->rentalShop($alpha['tenant'], 2, 'UTC');
        $booking = $this->hold($alpha['tenant']->id, $shop['service']->id, 'Ada');
        Livewire::test(BookingShow::class, ['booking' => $booking->id])->call('cancelBooking');
        $actionId = (string) StaffUndoAction::query()->value('id');

        Carbon::setTestNow(now()->addSeconds(11));
        Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->call('undoAction', $actionId)
            ->assertStatus(409);
        $this->assertSame('held', $booking->fresh()?->status->value);
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.cancelled')->count());

        $this->artisan('undo:commit')->assertSuccessful()->expectsOutput('undo_committed=1');
        $this->assertSame('cancelled', $booking->fresh()?->status->value);
        $this->assertSame(0, Hold::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.cancelled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.cancelled')->count());
    }

    public function test_deleted_inventory_closures_promos_and_waitlist_rows_restore_exactly(): void
    {
        $alpha = $this->signedIn();
        $shop = $this->rentalShop($alpha['tenant']);
        $asset = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'North canoe',
            'capacity' => 3,
            'turnaround_minutes' => 15,
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Spare paddles',
            'quantity' => 8,
            'turnaround_minutes' => 5,
        ]);

        Livewire::test(Resources::class)
            ->call('deleteResource', 'asset', $asset->id)
            ->assertSee('North canoe removed.')
            ->assertSee('Undo')
            ->assertSee('@media (max-width: 390px)', false)
            ->call('undoAction', (string) StaffUndoAction::query()->value('id'));

        $asset->refresh();
        $this->assertSame('North canoe', $asset->name);
        $this->assertSame($shop['location']->id, $asset->location_id);
        $this->assertSame(3, $asset->capacity);
        $this->assertSame(15, $asset->turnaround_minutes);
        $this->assertSame(ResourceStatus::Active, $asset->status);
        $this->assertNull($asset->deleted_at);

        Livewire::test(Resources::class)
            ->call('deleteResource', 'pool', $pool->id)
            ->call('undoAction', (string) StaffUndoAction::query()->latest('id')->value('id'));
        $pool->refresh();
        $this->assertSame('Spare paddles', $pool->name);
        $this->assertSame(8, $pool->quantity);
        $this->assertSame(5, $pool->turnaround_minutes);
        $this->assertNull($pool->deleted_at);

        $closure = LocationClosure::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'reason' => 'Holiday',
            'all_day' => true,
            'recurs_yearly' => false,
            'starts_on' => '2026-07-04',
            'ends_on' => '2026-07-04',
        ]);
        $hours = app(OpeningHours::class);
        $start = CarbonImmutable::parse('2026-07-04 14:00:00', 'UTC');
        $end = CarbonImmutable::parse('2026-07-04 15:00:00', 'UTC');
        $this->assertFalse($hours->assess($shop['location'], $start, $end)['open']);

        Livewire::test(Closures::class)
            ->call('remove', $closure->id)
            ->assertSee('Undo')
            ->assertSee('@media (max-width: 390px)', false);
        $this->assertNull(LocationClosure::query()->find($closure->id));
        $this->assertTrue($hours->assess($shop['location'], $start, $end)['open']);

        Livewire::test(Closures::class)
            ->call('undoAction', (string) StaffUndoAction::query()->latest('id')->value('id'));
        $closure->refresh();
        $this->assertSame('Holiday', $closure->reason);
        $this->assertTrue($closure->all_day);
        $this->assertFalse($closure->recurs_yearly);
        $this->assertSame('2026-07-04', $closure->starts_on->toDateString());
        $this->assertSame('2026-07-04', $closure->ends_on->toDateString());
        $this->assertSame($shop['location']->id, $closure->location_id);
        $this->assertNull($closure->deleted_at);
        $this->assertFalse($hours->assess($shop['location'], $start, $end)['open']);

        Livewire::test(PromoCodes::class)
            ->set('code', 'summer')
            ->set('discountType', 'percent')
            ->set('discountValue', '10')
            ->set('startsOn', '2026-06-01')
            ->set('endsOn', '2026-08-31')
            ->set('usageLimit', '5')
            ->call('create')
            ->assertHasNoErrors();
        $promo = PromoCode::query()->where('code', 'SUMMER')->firstOrFail();
        Livewire::test(PromoCodes::class)->call('delete', $promo->id)->assertSee('SUMMER removed.')->assertSee('Undo');
        $this->assertNull(PromoCode::query()->where('code', 'SUMMER')->first());
        Livewire::test(PromoCodes::class)
            ->set('code', 'SUMMER')
            ->set('discountValue', '10')
            ->call('create')
            ->assertHasErrors('code');

        Livewire::test(PromoCodes::class)
            ->call('undoAction', (string) StaffUndoAction::query()->latest('id')->value('id'));
        $promo->refresh();
        $this->assertSame('SUMMER', $promo->code);
        $this->assertSame(10, $promo->percent_off);
        $this->assertNull($promo->amount_off_cents);
        $this->assertSame('2026-06-01', $promo->starts_on?->toDateString());
        $this->assertSame('2026-08-31', $promo->ends_on?->toDateString());
        $this->assertSame(5, $promo->usage_limit);
        $this->assertTrue($promo->active);
        $this->assertNull($promo->deleted_at);

        Livewire::test(PromoCodes::class)->call('delete', $promo->id);
        Carbon::setTestNow(now()->addSeconds(11));
        $this->artisan('undo:commit')->assertSuccessful();
        Carbon::setTestNow();
        $this->assertNull(PromoCode::query()->where('code', 'SUMMER')->first());
        $retired = PromoCode::withTrashed()->find($promo->id);
        $this->assertNotNull($retired);
        $this->assertNotSame('SUMMER', $retired->code);
        $this->assertTrue($retired->trashed());
        Livewire::test(PromoCodes::class)
            ->set('code', 'SUMMER')
            ->set('discountValue', '10')
            ->call('create')
            ->assertHasNoErrors();

        $starts = CarbonImmutable::parse('2026-06-15 15:00:00', 'UTC');
        $entry = app(Waitlist::class)->join(
            $alpha['tenant']->id,
            $shop['service']->id,
            null,
            $shop['location']->id,
            $starts,
            $starts->addHour(),
            2,
            'Ada Kayak',
            'ada@example.com',
            null,
            'ada-wait',
            null,
            '312-555-0100',
        );
        Livewire::test(WaitlistPage::class)
            ->set('date', '2026-06-15')
            ->call('cancel', $entry->id)
            ->assertSee('Removed from the waitlist.')
            ->assertSee('Undo')
            ->assertSee('@media (max-width: 390px)', false);
        $this->assertSame(WaitlistStatus::Cancelled, $entry->fresh()?->status);

        Livewire::test(WaitlistPage::class)
            ->call('undoAction', (string) StaffUndoAction::query()->latest('id')->value('id'));
        $entry->refresh();
        $this->assertSame(WaitlistStatus::Waiting, $entry->status);
        $this->assertSame('Ada Kayak', $entry->customer_name);
        $this->assertSame('ada@example.com', $entry->customer_email);
        $this->assertSame('312-555-0100', $entry->customer_phone);
        $this->assertSame(2, $entry->participants);
        $this->assertSame($shop['service']->id, $entry->service_id);
        $this->assertTrue($entry->starts_at->equalTo($starts));
    }

    public function test_undo_is_limited_to_the_actor_workspace_and_location(): void
    {
        $alpha = $this->signedIn('manager');
        $shop = $this->rentalShop($alpha['tenant']);
        $south = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'South dock',
            'timezone' => 'America/New_York',
        ]);
        $southAsset = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South canoe',
        ]);
        $northAsset = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'North canoe',
        ]);
        $this->limit($alpha['membership'], $shop['location']->id);

        Livewire::test(Resources::class)
            ->call('deleteResource', 'asset', $southAsset->id)
            ->assertNotFound();
        $this->assertNotNull(Asset::withoutGlobalScopes()->find($southAsset->id));
        $this->assertNull(Asset::withoutGlobalScopes()->find($southAsset->id)?->deleted_at);

        Livewire::test(Resources::class)->call('deleteResource', 'asset', $northAsset->id);
        $action = StaffUndoAction::query()->firstOrFail();
        $this->limit($alpha['membership'], $south->id);
        Livewire::test(Resources::class)
            ->call('undoAction', $action->id)
            ->assertNotFound();
        $this->assertNotNull(Asset::withoutGlobalScopes()->find($northAsset->id)?->deleted_at);

        $other = User::factory()->create();
        Membership::factory()->role('owner')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $other->id,
        ]);
        $this->actingAs($other);
        app(StaffLocationAccess::class)->forget();
        Livewire::test(Resources::class)
            ->call('undoAction', $action->id)
            ->assertNotFound();

        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);
        Livewire::test(Resources::class)
            ->call('undoAction', $action->id)
            ->assertNotFound();
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertNotNull(Asset::withoutGlobalScopes()->find($northAsset->id)?->deleted_at);

        $this->actingAs($alpha['user']);
        $promo = PromoCode::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'code' => 'KEEP',
            'percent_off' => 10,
            'active' => true,
        ]);
        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $staff->id,
        ]);
        $this->actingAs($staff);
        Livewire::test(PromoCodes::class)->call('delete', $promo->id)->assertForbidden();
        Livewire::test(Resources::class)->call('deleteResource', 'asset', $southAsset->id)->assertForbidden();
        $this->assertTrue($promo->fresh()?->active);
        $this->assertNull($promo->fresh()?->deleted_at);
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

    private function hold(string $tenantId, string $serviceId, string $name): Booking
    {
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(10, 0);

        return app(Allocator::class)->hold(new AllocationIntent(
            $tenantId,
            $serviceId,
            $start,
            $start->addHour(),
            1,
            null,
            $name,
            'ada@example.com',
        ));
    }

    private function limit(Membership $membership, string ...$locationIds): void
    {
        $membership->locations_restricted = true;
        $membership->save();
        MembershipLocation::query()->where('membership_id', $membership->id)->delete();

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
