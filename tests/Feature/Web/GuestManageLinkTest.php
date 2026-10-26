<?php

namespace Tests\Feature\Web;

use App\Models\Booking;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Refund;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Support\BookingReference;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class GuestManageLinkTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_owners_and_managers_can_open_the_guest_manage_link_and_staff_cannot(): void
    {
        $workspace = $this->workspace('dock.test');
        app(TenantManager::class)->set($workspace['tenant']);
        $shop = $this->rentalShop($workspace['tenant']);
        $start = CarbonImmutable::now('America/New_York')->addDays(3)->setTime(10, 0);
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $shop['service']->id,
            $start->utc(),
            $start->utc()->addHour(),
            1,
            'guest-link',
            'Ada Lovelace',
            'ada@example.com',
        ));
        $booking = app(Allocator::class)->confirm($booking);
        $manager = User::factory()->create();
        $staff = User::factory()->create();
        Membership::factory()->role('manager')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $manager->id,
        ]);
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);
        app(TenantManager::class)->forget();

        $this->actingAs($workspace['user']);
        $ownerPage = $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$booking->id))->assertOk();
        $ownerPage->assertSee('Guest manage link')->assertSee('Copy link');
        $this->assertSame(1, preg_match('/data-guest-manage-link="1" value="([^"]+)"/', (string) $ownerPage->getContent(), $link));
        $manage = html_entity_decode($link[1]);
        $this->assertStringContainsString('http://dock.test/book/', $manage);
        $this->assertStringContainsString('/manage/'.$booking->id, $manage);

        $this->get($manage)
            ->assertOk()
            ->assertSee('Booking '.BookingReference::short($booking->id))
            ->assertSee('Length: 1 hour')
            ->assertSee('The price stays the same.')
            ->assertDontSee('Assign a guide');

        $this->actingAs($manager);
        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$booking->id))
            ->assertOk()
            ->assertSee('Guest manage link');

        $this->actingAs($staff);
        $this->get($this->tenantUrl('dock.test', '/staff/bookings/'.$booking->id))
            ->assertOk()
            ->assertDontSee('Guest manage link')
            ->assertDontSee('data-guest-manage-link', false);

        $other = $this->workspace('creek.test');
        $this->actingAs($other['user']);
        $this->get(str_replace('http://dock.test', 'http://creek.test', $manage))->assertNotFound();
        $this->get($this->tenantUrl('creek.test', '/staff/bookings/'.$booking->id))->assertNotFound();

        app(TenantManager::class)->set($workspace['tenant']);
        $fresh = Booking::query()->findOrFail($booking->id);
        $this->assertSame('confirmed', $fresh->status->value);
        $this->assertTrue($fresh->starts_at->equalTo($booking->starts_at));
        $this->assertSame(0, Payment::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, Refund::query()->where('booking_id', $booking->id)->count());
        $this->assertSame(0, PlatformFee::query()->where('booking_id', $booking->id)->count());
    }
}
