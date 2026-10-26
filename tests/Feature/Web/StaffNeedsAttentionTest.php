<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\MaintenanceKind;
use App\Livewire\Staff\Bookings;
use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\Dashboard;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Operations\MaintenancePlanner;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffNeedsAttentionTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_flagged_and_payment_exception_bookings_reach_the_home_page_list_and_booking(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $allocator = app(Allocator::class);
        $flagged = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-07-04 14:00:00', 'flagged', 'Flagged Guest')));
        $calm = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-07-05 14:00:00', 'calm', 'Calm Guest')));
        $lost = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-07-06 14:00:00', 'lost', 'Lost Guest')));
        $lost->forceFill(['status' => BookingStatus::PaymentException])->save();
        app(MaintenancePlanner::class)->schedule($shop['pool'], MaintenanceKind::Maintenance, CarbonImmutable::parse('2026-07-04 13:00:00', 'UTC'), CarbonImmutable::parse('2026-07-04 16:00:00', 'UTC'), 'Hull repair');
        $this->actingAs($alpha['user']);

        Livewire::test(Dashboard::class)
            ->assertSee('Needs attention')
            ->assertSeeHtml('data-attention="2"')
            ->assertSeeHtml('href="/staff/bookings?status=attention&when=all"');

        $list = Livewire::withQueryParams(['status' => 'attention', 'when' => 'all'])->test(Bookings::class);
        $list->assertSee('Flagged Guest')->assertSee('Lost Guest')->assertDontSee('Calm Guest');

        Livewire::test(BookingShow::class, ['booking' => $lost->id])
            ->assertSeeHtml('data-needs-attention="payment_exception"')
            ->assertSee('Paid, but the time was no longer free.');

        Livewire::test(BookingShow::class, ['booking' => $flagged->id])
            ->assertSeeHtml('data-needs-attention="flagged"')
            ->assertSee('Hull repair')
            ->call('clearAttention')
            ->assertSee('Marked handled.')
            ->assertDontSeeHtml('data-needs-attention');

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertNull($flagged->fresh()->attention_reason);
        $this->assertSame('confirmed', $flagged->fresh()->status->value);
        $audit = AuditLog::query()->where('action', 'booking.attention_cleared')->sole();
        $this->assertSame('Hull repair', $audit->properties['reason'] ?? null);

        Livewire::test(Dashboard::class)->assertSeeHtml('data-attention="1"');
        $this->assertNull(Booking::query()->findOrFail($calm->id)->attention_reason);
    }

    public function test_only_members_who_can_change_bookings_can_mark_one_handled(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $allocator = app(Allocator::class);
        $booking = $allocator->confirm($allocator->hold($this->intent($alpha['tenant']->id, $shop['service']->id, '2026-07-04 14:00:00', 'flag', 'Flagged Guest')));
        $booking->forceFill(['attention_reason' => 'Asset is maintenance.'])->save();
        $viewer = User::factory()->create();
        $role = Role::query()->create(['slug' => 'viewer', 'name' => 'Viewer']);
        $role->permissions()->sync([Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id]);
        Membership::factory()->create(['tenant_id' => $alpha['tenant']->id, 'user_id' => $viewer->id, 'role_id' => $role->id]);
        $this->actingAs($viewer);

        Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->assertSee('Asset is maintenance.')
            ->assertDontSee('Mark handled')
            ->call('clearAttention')
            ->assertForbidden();

        $this->assertSame('Asset is maintenance.', $booking->fresh()->attention_reason);
    }

    private function intent(string $tenantId, string $serviceId, string $start, string $key, string $name): AllocationIntent
    {
        $at = CarbonImmutable::parse($start, 'UTC');

        return new AllocationIntent($tenantId, $serviceId, $at, $at->addHour(), 1, $key, $name, $key.'@example.com');
    }
}
