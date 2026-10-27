<?php

namespace Tests\Feature\Operations;

use App\Enums\OperationalStatus;
use App\Livewire\Staff\BookingShow;
use App\Livewire\Staff\NewBooking;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class OperationalStatusFlowTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_checkout_and_return_move_operational_status_and_leave_money_alone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', 'UTC'));
        [$workspace, $service] = $this->rental('dock.test');
        $other = $this->confirmedWorkspace('cove.test', 'other-quiet');
        $this->actingAsWorkspace($workspace);
        $money = $this->moneyCounts();

        $fromNotArrived = $this->confirmed($service, '18:00', '19:00', 'status-out');
        $fromCheckedIn = $this->confirmed($service, '15:00', '16:00', 'status-checked-in');
        $staysCompleted = $this->confirmed($service, '13:00', '14:00', 'status-completed');
        $staysOverdue = $this->confirmed($service, '11:00', '12:00', 'status-already-overdue');
        $this->setOperational($fromCheckedIn, OperationalStatus::CheckedIn);
        $this->setOperational($staysCompleted, OperationalStatus::Completed);
        $this->setOperational($staysOverdue, OperationalStatus::Overdue);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$fromNotArrived.'/checkout'))
            ->assertOk()
            ->assertJsonPath('data.operational_status', 'in_progress');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$fromCheckedIn.'/checkout'))
            ->assertOk()
            ->assertJsonPath('data.operational_status', 'in_progress');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$staysCompleted.'/checkout'))
            ->assertOk()
            ->assertJsonPath('data.operational_status', 'completed');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$staysOverdue.'/checkout'))
            ->assertOk()
            ->assertJsonPath('data.operational_status', 'overdue');

        $stamp = $this->booking($fromNotArrived)->updated_at;
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:05:00', 'UTC'));
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$fromNotArrived.'/checkout'))
            ->assertOk()
            ->assertJsonPath('data.operational_status', 'in_progress');
        $this->assertTrue($stamp->equalTo($this->booking($fromNotArrived)->updated_at));

        app(TenantManager::class)->set($workspace['tenant']);
        TenantSetting::query()->update(['late_fee_per_minute_cents' => 50]);
        app(TenantManager::class)->forget();
        Carbon::setTestNow(Carbon::parse('2026-06-16 19:20:00', 'UTC'));
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$fromNotArrived.'/return'))
            ->assertOk()
            ->assertJsonPath('data.operational_status', 'returned');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$staysCompleted.'/return'))
            ->assertOk()
            ->assertJsonPath('data.operational_status', 'completed');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1_000, RentalCheckout::query()->where('booking_id', $fromNotArrived)->firstOrFail()->late_fee_cents);
        $this->assertSame(0, PricingSnapshot::query()->count());
        app(TenantManager::class)->forget();

        $this->assertSame(OperationalStatus::NotArrived, $this->booking($other)->operational_status);
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_the_overdue_sweep_sets_overdue_once_and_leaves_finished_visits(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', 'UTC'));
        [$workspace, $service] = $this->rental('dock.test');
        $other = $this->confirmedWorkspace('cove.test', 'other-sweep');
        $this->actingAsWorkspace($workspace);
        $money = $this->moneyCounts();

        $inProgress = $this->confirmed($service, '10:00', '11:00', 'sweep-progress');
        $notArrived = $this->confirmed($service, '11:00', '12:00', 'sweep-absent');
        $checkedIn = $this->confirmed($service, '12:00', '13:00', 'sweep-arrived');
        $returned = $this->confirmed($service, '13:00', '14:00', 'sweep-back');
        $completed = $this->confirmed($service, '14:00', '15:00', 'sweep-done');

        foreach ([$inProgress, $notArrived, $checkedIn, $returned, $completed] as $bookingId) {
            $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/checkout'))->assertOk();
        }

        $this->setOperational($notArrived, OperationalStatus::NotArrived);
        $this->setOperational($checkedIn, OperationalStatus::CheckedIn);
        $this->setOperational($returned, OperationalStatus::Returned);
        $this->setOperational($completed, OperationalStatus::Completed);

        Carbon::setTestNow(Carbon::parse('2026-06-16 16:00:00', 'UTC'));
        $this->artisan('rentals:flag-overdue')->assertSuccessful();

        $this->assertSame(OperationalStatus::Overdue, $this->booking($inProgress)->operational_status);
        $this->assertSame(OperationalStatus::Overdue, $this->booking($notArrived)->operational_status);
        $this->assertSame(OperationalStatus::Overdue, $this->booking($checkedIn)->operational_status);
        $this->assertSame(OperationalStatus::Returned, $this->booking($returned)->operational_status);
        $this->assertSame(OperationalStatus::Completed, $this->booking($completed)->operational_status);
        $this->assertSame(OperationalStatus::NotArrived, $this->booking($other)->operational_status);

        $stamp = $this->booking($inProgress)->updated_at;
        Carbon::setTestNow(Carbon::parse('2026-06-16 16:05:00', 'UTC'));
        $this->artisan('rentals:flag-overdue')->assertSuccessful();

        $this->assertTrue($stamp->equalTo($this->booking($inProgress)->updated_at));
        $this->assertSame(OperationalStatus::Returned, $this->booking($returned)->operational_status);
        $this->assertSame(OperationalStatus::Completed, $this->booking($completed)->operational_status);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, NotificationHook::query()->where('booking_id', $inProgress)->where('kind', 'rental.overdue')->count());
        $this->assertSame(5, NotificationHook::query()->where('kind', 'rental.overdue')->count());
        app(TenantManager::class)->forget();

        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_the_staff_booking_page_shows_in_progress_then_returned(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $start = CarbonImmutable::now('UTC')->addDays(3)->setTime(14, 0);
        $money = $this->moneyCounts();

        Livewire::test(NewBooking::class, ['serviceId' => $shop['service']->id])
            ->set('date', $start->toDateString())
            ->set('startsAt', $start->toIso8601String())
            ->set('customerName', 'Ada')
            ->set('customerEmail', 'ada@example.com')
            ->call('book')
            ->assertHasNoErrors();

        $booking = Booking::query()->firstOrFail();

        $checkedOut = Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->call('confirm')
            ->assertSet('problem', null)
            ->call('checkOut')
            ->assertSet('problem', null)
            ->assertSee('Checked out. The gear is on the water.');

        $this->assertMatchesRegularExpression(
            '/<option value="in_progress"[^>]*selected/',
            $checkedOut->html(),
        );
        $this->assertSame(OperationalStatus::InProgress, $booking->fresh()->operational_status);

        $returned = Livewire::test(BookingShow::class, ['booking' => $booking->id])
            ->call('markReturned')
            ->assertSet('problem', null)
            ->assertSee('Returned. The gear is free for the next booking.');

        $this->assertMatchesRegularExpression(
            '/<option value="returned"[^>]*selected/',
            $returned->html(),
        );
        $this->assertSame(OperationalStatus::Returned, $booking->fresh()->operational_status);
        $this->assertSame($money['payments'], $this->moneyCounts()['payments']);
        $this->assertSame($money['refunds'], $this->moneyCounts()['refunds']);
        $this->assertSame($money['fees'], $this->moneyCounts()['fees']);
        $this->assertSame($money['fee_refunds'], $this->moneyCounts()['fee_refunds']);
        $this->assertSame($money['taxes'], $this->moneyCounts()['taxes']);
    }

    /**
     * @return array{prices: int, payments: int, refunds: int, fees: int, fee_refunds: int, taxes: int}
     */
    private function moneyCounts(): array
    {
        return [
            'prices' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }

    private function setOperational(string $bookingId, OperationalStatus $status): void
    {
        $booking = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($bookingId);
        app(TenantManager::class)->set(Tenant::query()->findOrFail($booking->tenant_id));
        $booking->operational_status = $status;
        $booking->save();
        app(TenantManager::class)->forget();
    }

    private function booking(string $bookingId): Booking
    {
        return Booking::withoutGlobalScope(TenantScope::class)->findOrFail($bookingId);
    }

    private function confirmedWorkspace(string $host, string $key): string
    {
        [$workspace, $service] = $this->rental($host);
        $this->actingAsWorkspace($workspace);
        $bookingId = $this->postJson($this->tenantUrl($host, '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T18:00:00Z',
            'ends_at' => '2026-06-16T19:00:00Z',
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->postJson($this->tenantUrl($host, '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(): array
    {
        $workspace = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    private function confirmed(Service $service, string $start, string $end, string $key): string
    {
        $bookingId = $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T'.$start.':00Z',
            'ends_at' => '2026-06-16T'.$end.':00Z',
            'participants' => 1,
            'idempotency_key' => $key,
        ])->assertCreated()->json('data.id');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/bookings/'.$bookingId.'/confirm'))->assertOk();

        return $bookingId;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(string $host): array
    {
        $workspace = $this->workspace($host);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
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

        return [$workspace, $service];
    }
}
