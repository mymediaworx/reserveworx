<?php

namespace Tests\Feature\Web;

use App\Enums\WaitlistStatus;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Livewire\Staff\Waitlist;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Booking\Waitlist as WaitlistService;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffWaitlistTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_can_list_promote_and_cancel_a_waitlist_for_one_date_and_service(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $shop['service']->waitlist_enabled = true;
        $shop['service']->save();
        $other = Service::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Sunset tour',
            'kind' => 'tour',
            'waitlist_enabled' => true,
        ]);
        $waiting = $this->entry($alpha['tenant']->id, $shop['service'], '2026-06-15 15:00:00', 'Ada Kayak', 'ada@example.com', '312-555-0100');
        $this->entry($alpha['tenant']->id, $other, '2026-06-15 18:00:00', 'Bea Paddle', 'bea@example.com', null);
        $this->entry($alpha['tenant']->id, $shop['service'], '2026-06-16 15:00:00', 'Cara Next', 'cara@example.com', null);
        app(TenantManager::class)->forget();

        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist'))->assertRedirect('/staff/login');

        $this->actingAs($alpha['user']);
        $page = $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-06-15'))
            ->assertOk()
            ->assertSee('Ada Kayak')
            ->assertSee('312-555-0100')
            ->assertSee('Bea Paddle')
            ->assertDontSee('Cara Next')
            ->assertSee('href="/staff/waitlist"', false)
            ->assertSee('@media (max-width: 390px)', false);
        $this->assertStringContainsString('Promote', (string) $page->getContent());

        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-06-15&service='.$shop['service']->id))
            ->assertOk()
            ->assertSee('Ada Kayak')
            ->assertDontSee('Bea Paddle');
        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-06-16'))
            ->assertOk()
            ->assertSee('Cara Next')
            ->assertDontSee('Ada Kayak');
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('href="/staff/waitlist"', false);
        $this->get($this->tenantUrl('alpha.test', '/staff/setup/services/'.$shop['service']->id))
            ->assertOk()
            ->assertSee('Offer a waitlist when it is full');

        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->set('waitlist', false)
            ->call('saveSettings')
            ->assertHasNoErrors();
        $this->assertFalse($shop['service']->fresh()?->waitlist_enabled);
        Livewire::test(ServiceDetail::class, ['service' => $shop['service']->id])
            ->set('waitlist', true)
            ->call('saveSettings')
            ->assertHasNoErrors();
        $this->assertTrue($shop['service']->fresh()?->waitlist_enabled);
        $this->assertTrue(AuditLog::query()->where('action', 'service.updated')->exists());

        Livewire::test(Waitlist::class)
            ->set('date', '2026-06-15')
            ->set('service', $shop['service']->id)
            ->call('promote', $waiting->id)
            ->assertSee('Promoted. The spot is held and the notification is queued.');
        $waiting->refresh();
        $this->assertSame(WaitlistStatus::Promoted, $waiting->status);
        $booking = Booking::query()->findOrFail($waiting->booking_id);
        $this->assertSame('held', $booking->status->value);
        $this->assertSame('312-555-0100', $booking->customer?->phone);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'waitlist.promoted')->count());
        $this->assertTrue(AuditLog::query()->where('action', 'waitlist.promoted')->where('subject_id', $waiting->id)->exists());

        $later = WaitlistEntry::query()->where('customer_email', 'cara@example.com')->firstOrFail();
        Livewire::test(Waitlist::class)
            ->set('date', '2026-06-16')
            ->call('cancel', $later->id)
            ->assertSee('Removed from the waitlist.');
        $this->assertSame(WaitlistStatus::Cancelled, $later->fresh()?->status);
        $this->assertTrue(AuditLog::query()->where('action', 'waitlist.cancelled')->where('subject_id', $later->id)->exists());
        app(TenantManager::class)->forget();
    }

    public function test_a_full_slot_cannot_be_promoted_and_permissions_are_enforced(): void
    {
        $alpha = $this->workspace('alpha.test');
        $staffUser = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $staffUser->id,
        ]);
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $shop['pool']->quantity = 1;
        $shop['pool']->save();
        $start = CarbonImmutable::parse('2026-06-15 15:00:00', 'UTC');
        app(Allocator::class)->hold(new AllocationIntent(
            $alpha['tenant']->id,
            $shop['service']->id,
            $start,
            $start->addHour(),
            1,
            'taken',
            'Taken Guest',
            'taken@example.com',
        ));
        $waiting = $this->entry($alpha['tenant']->id, $shop['service'], '2026-06-15 15:00:00', 'Ada Kayak', 'ada@example.com', null);
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user']);
        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Waitlist::class)
            ->set('date', '2026-06-15')
            ->call('promote', $waiting->id)
            ->assertSee('That time is still full.');
        $this->assertSame(WaitlistStatus::Waiting, $waiting->fresh()?->status);
        $this->assertSame(1, Booking::query()->count());
        app(TenantManager::class)->forget();

        $this->actingAs($beta['user']);
        $this->get($this->tenantUrl('beta.test', '/staff/waitlist?date=2026-06-15'))
            ->assertOk()
            ->assertDontSee('Ada Kayak');
        app(TenantManager::class)->set($beta['tenant']);
        Livewire::test(Waitlist::class)->call('cancel', $waiting->id)->assertNotFound();
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(WaitlistStatus::Waiting, $waiting->fresh()?->status);

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.manage')->firstOrFail()->id);
        $this->actingAs($staffUser);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist?date=2026-06-15'))
            ->assertOk()
            ->assertSee('Ada Kayak')
            ->assertDontSee('>Promote<', false);
        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Waitlist::class)->set('date', '2026-06-15')->call('promote', $waiting->id)->assertForbidden();
        Livewire::test(Waitlist::class)->call('cancel', $waiting->id)->assertForbidden();
        $this->assertSame(WaitlistStatus::Waiting, $waiting->fresh()?->status);

        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id);
        $this->get($this->tenantUrl('alpha.test', '/staff/waitlist'))->assertForbidden();
    }

    private function entry(string $tenantId, Service $service, string $start, string $name, string $email, ?string $phone): WaitlistEntry
    {
        $starts = CarbonImmutable::parse($start, 'UTC');

        return app(WaitlistService::class)->join(
            $tenantId,
            $service->id,
            null,
            $service->location_id,
            $starts,
            $starts->addHour(),
            1,
            $name,
            $email,
            null,
            $email,
            null,
            $phone,
        );
    }
}
