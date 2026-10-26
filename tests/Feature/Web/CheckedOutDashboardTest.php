<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MembershipLocation;
use App\Models\Pool;
use App\Models\RentalCheckout;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CheckedOutDashboardTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_home_lists_rentals_that_are_out_and_not_due_yet(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $harbor = $this->location($alpha['tenant'], 'Harbor', 'America/New_York');
        $later = $this->checkout($alpha['tenant'], $harbor, 'Later Guest', 'Harbor kayak', null, '2026-06-16 15:00:00', '2026-06-18 02:00:00');
        $pool = $this->checkout($alpha['tenant'], $harbor, 'Pool Guest', null, 'Single kayaks', '2026-06-16 15:00:00', '2026-06-17 16:00:00');
        $late = $this->checkout($alpha['tenant'], $harbor, 'Late Guest', 'Late kayak', null, '2026-06-16 14:00:00', '2026-06-16 16:00:00');
        $returned = $this->checkout($alpha['tenant'], $harbor, 'Back Guest', 'Returned kayak', null, '2026-06-16 12:00:00', '2026-06-18 12:00:00');
        $returned['checkout']->forceFill(['returned_at' => '2026-06-16 17:00:00'])->save();
        $this->checkout($beta['tenant'], $this->location($beta['tenant'], 'Other dock', 'UTC'), 'Beta Guest', 'Beta kayak', null, '2026-06-16 15:00:00', '2026-06-18 15:00:00');
        app(TenantManager::class)->forget();

        $page = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('data-checked-out="3"', false)
            ->assertSee('data-checked-out-later="2"', false)
            ->assertSee('data-overdue="1"', false)
            ->assertSee('data-checked-out-row="'.$later['checkout']->id.'"', false)
            ->assertSee('data-checked-out-row="'.$pool['checkout']->id.'"', false)
            ->assertSee('Harbor kayak')
            ->assertSee('Later Guest')
            ->assertSee('Single kayaks')
            ->assertSee('Pool Guest')
            ->assertSee('Due Wed, Jun 17, 10:00 PM')
            ->assertSee('Due Wed, Jun 17, 12:00 PM')
            ->assertSee('data-overdue-checkout="'.$late['checkout']->id.'"', false)
            ->assertSee('Late Guest')
            ->assertDontSee('data-checked-out-row="'.$late['checkout']->id.'"', false)
            ->assertDontSee('data-checked-out-row="'.$returned['checkout']->id.'"', false)
            ->assertDontSee('data-overdue-checkout="'.$returned['checkout']->id.'"', false)
            ->assertDontSee('Beta Guest')
            ->assertDontSee('Beta kayak');

        $html = (string) $page->getContent();
        $this->assertStringContainsString('@media (max-width: 390px)', $html);
        $this->assertStringContainsString('.checked-out-list', $html);

        $this->actingAsWorkspace($alpha);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/today'))
            ->assertOk()
            ->assertJsonPath('data.open_checkouts', 3)
            ->assertJsonPath('data.overdue_checkouts', 1);
    }

    public function test_a_limited_member_does_not_see_a_checkout_at_another_location(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test', 'staff');
        $north = $this->location($alpha['tenant'], 'North dock', 'UTC');
        $south = $this->location($alpha['tenant'], 'South dock', 'UTC');
        $this->checkout($alpha['tenant'], $north, 'North Guest', 'North kayak', null, '2026-06-16 15:00:00', '2026-06-18 15:00:00');
        $this->checkout($alpha['tenant'], $south, 'South Guest', 'South kayak', null, '2026-06-16 15:00:00', '2026-06-18 15:00:00');
        $alpha['membership']->locations_restricted = true;
        $alpha['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'membership_id' => $alpha['membership']->id,
            'location_id' => $north->id,
        ]);
        app(StaffLocationAccess::class)->forget();
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('data-checked-out="1"', false)
            ->assertSee('data-checked-out-later="1"', false)
            ->assertSee('North Guest')
            ->assertSee('North kayak')
            ->assertDontSee('South Guest')
            ->assertDontSee('South kayak');
    }

    public function test_the_due_later_list_stops_at_twenty_and_keeps_the_full_count(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $harbor = $this->location($alpha['tenant'], 'Harbor', 'UTC');

        for ($number = 1; $number <= 21; $number++) {
            $this->checkout(
                $alpha['tenant'],
                $harbor,
                'Guest '.$number,
                'Kayak '.$number,
                null,
                '2026-06-16 15:00:00',
                '2026-06-17 15:00:00',
            );
        }

        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('data-checked-out="21"', false)
            ->assertSee('data-checked-out-later="21"', false)
            ->assertSee('Showing 20 of 21.');
    }

    private function location(Tenant $tenant, string $name, string $timezone): Location
    {
        app(TenantManager::class)->set($tenant);

        return Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    /**
     * @return array{booking: Booking, checkout: RentalCheckout}
     */
    private function checkout(
        Tenant $tenant,
        Location $location,
        string $customerName,
        ?string $assetName,
        ?string $poolName,
        string $checkedOutAt,
        string $dueAt,
    ): array {
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => ($assetName ?? $poolName).' rental',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '.', $customerName)).'@example.com',
        ]);
        $asset = null;
        $pool = null;

        if ($assetName !== null) {
            $asset = Asset::factory()->create([
                'tenant_id' => $tenant->id,
                'location_id' => $location->id,
                'name' => $assetName,
            ]);
        }

        if ($poolName !== null) {
            $pool = Pool::factory()->create([
                'tenant_id' => $tenant->id,
                'location_id' => $location->id,
                'name' => $poolName,
            ]);
        }

        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::InProgress,
            'starts_at' => $checkedOutAt,
            'ends_at' => $dueAt,
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerName.$dueAt),
        ]);
        $checkout = RentalCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset?->id,
            'pool_id' => $pool?->id,
            'quantity' => 1,
            'checked_out_at' => $checkedOutAt,
            'due_at' => $dueAt,
        ]);

        return compact('booking', 'checkout');
    }
}
