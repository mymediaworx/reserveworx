<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Setup\ServiceDetail;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Domain;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffDepartureCancelTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_cancels_an_empty_departure_and_a_later_hold_is_refused(): void
    {
        $workspace = $this->signedIn();
        $tour = $this->tour($workspace['tenant']->id);
        $start = CarbonImmutable::now('UTC')->addDays(14)->setTime(15, 0);
        $departure = $this->departure($tour['service'], $start);
        $money = $this->moneyCounts();

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/services/'.$tour['service']->id))
            ->assertOk()
            ->assertSee('Cancel departure')
            ->assertSee('Nothing is refunded.')
            ->assertSee('data-departure="'.$departure->id.'"', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('min-height: 44px', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        Livewire::test(ServiceDetail::class, ['service' => $tour['service']->id])
            ->call('cancelDeparture', $departure->id)
            ->assertHasNoErrors()
            ->assertSee('Departure cancelled.')
            ->assertSee(', cancelled')
            ->assertDontSee('Cancel departure')
            ->call('cancelDeparture', $departure->id)
            ->assertSee('That departure is already cancelled.');

        $departure->refresh();
        $this->assertSame(DepartureStatus::Cancelled, $departure->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'departure.cancelled')->where('subject_id', $departure->id)->count());
        $this->assertSame($money, $this->moneyCounts());

        Sanctum::actingAs($workspace['user']);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings'), [
            'service_id' => $tour['service']->id,
            'departure_id' => $departure->id,
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $start->addHours(2)->toIso8601String(),
            'participants' => 2,
            'idempotency_key' => 'after-cancel',
        ])->assertStatus(409)->assertJsonPath('message', 'That departure is cancelled.');

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(0, Booking::query()->count());
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_a_live_party_keeps_the_departure_and_a_finished_party_does_not(): void
    {
        $workspace = $this->signedIn();
        $workspaceId = $workspace['tenant']->id;
        $tour = $this->tour($workspaceId);
        $start = CarbonImmutable::now('UTC')->addDays(20)->setTime(15, 0);
        $short = $this->departure($tour['service'], $start);
        $held = $this->departure($tour['service'], $start->addDay());
        $pending = $this->departure($tour['service'], $start->addDays(2));
        $finished = $this->departure($tour['service'], $start->addDays(3));
        $noShow = $this->departure($tour['service'], $start->addDays(4));

        $confirmed = $this->party($tour['service'], $short, BookingStatus::Confirmed, $workspaceId);
        $heldBooking = $this->party($tour['service'], $held, BookingStatus::Held, $workspaceId);
        Hold::query()->create([
            'tenant_id' => $workspaceId,
            'booking_id' => $heldBooking->id,
            'asset_id' => $tour['asset']->id,
            'quantity' => 2,
            'starts_at' => $held->starts_at,
            'ends_at' => $held->ends_at,
            'expires_at' => now()->addMinutes(15),
        ]);
        $pendingBooking = $this->party($tour['service'], $pending, BookingStatus::PendingPayment, $workspaceId);
        $cancelled = $this->party($tour['service'], $finished, BookingStatus::Cancelled, $workspaceId);
        $missed = $this->party($tour['service'], $noShow, BookingStatus::NoShow, $workspaceId);
        $money = $this->moneyCounts();

        Livewire::test(ServiceDetail::class, ['service' => $tour['service']->id])
            ->assertSee('data-below-minimum="'.$short->id.'"', false)
            ->assertSee('Below minimum')
            ->call('cancelDeparture', $short->id)
            ->assertSee('Cancel the bookings on this departure first.')
            ->call('cancelDeparture', $held->id)
            ->assertSee('Cancel the bookings on this departure first.')
            ->call('cancelDeparture', $pending->id)
            ->assertSee('Cancel the bookings on this departure first.')
            ->call('cancelDeparture', $finished->id)
            ->assertSee('Departure cancelled.')
            ->call('cancelDeparture', $noShow->id)
            ->assertSee('Departure cancelled.');

        $this->assertSame(DepartureStatus::Scheduled, $short->refresh()->status);
        $this->assertSame(DepartureStatus::Scheduled, $held->refresh()->status);
        $this->assertSame(DepartureStatus::Scheduled, $pending->refresh()->status);
        $this->assertSame(DepartureStatus::Cancelled, $finished->refresh()->status);
        $this->assertSame(DepartureStatus::Cancelled, $noShow->refresh()->status);
        $this->assertSame(BookingStatus::Confirmed, $confirmed->refresh()->status);
        $this->assertSame(BookingStatus::Held, $heldBooking->refresh()->status);
        $this->assertSame(BookingStatus::PendingPayment, $pendingBooking->refresh()->status);
        $this->assertSame(BookingStatus::Cancelled, $cancelled->refresh()->status);
        $this->assertSame(BookingStatus::NoShow, $missed->refresh()->status);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_staff_and_another_workspace_cannot_cancel_a_departure(): void
    {
        $workspace = $this->signedIn();
        $tour = $this->tour($workspace['tenant']->id);
        $departure = $this->departure($tour['service'], CarbonImmutable::now('UTC')->addDays(30)->setTime(15, 0));
        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);
        $this->actingAs($staff);

        Livewire::test(ServiceDetail::class, ['service' => $tour['service']->id])
            ->assertSee('data-departure="'.$departure->id.'"', false)
            ->assertDontSee('Cancel departure')
            ->call('cancelDeparture', $departure->id)
            ->assertForbidden();

        $this->assertSame(DepartureStatus::Scheduled, $departure->refresh()->status);

        $this->actingAs($workspace['user']);
        $elsewhere = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South dock',
        ]);
        $workspace['membership']->locations_restricted = true;
        $workspace['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'membership_id' => $workspace['membership']->id,
            'location_id' => $elsewhere->id,
        ]);
        app(StaffLocationAccess::class)->forget();

        Livewire::test(ServiceDetail::class, ['service' => $tour['service']->id])
            ->assertNotFound();
        $this->assertSame(DepartureStatus::Scheduled, $departure->refresh()->status);

        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);

        Livewire::test(ServiceDetail::class, ['service' => $tour['service']->id])
            ->assertNotFound();
        $this->assertSame(DepartureStatus::Scheduled, $departure->refresh()->status);

        $rental = $this->rentalOn($beta['tenant']->id);
        $foreign = $this->departure($rental, CarbonImmutable::now('UTC')->addDays(31)->setTime(15, 0));

        Livewire::test(ServiceDetail::class, ['service' => $rental->id])
            ->call('cancelDeparture', $foreign->id)
            ->assertStatus(422);
        $this->assertSame(DepartureStatus::Scheduled, $foreign->refresh()->status);
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

    /**
     * @return array{service: Service, asset: Asset}
     */
    private function tour(string $tenantId): array
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => 'UTC',
            'name' => 'Harbor Point',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => 'Tour boat',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => 'Sunset tour',
            'kind' => ServiceKind::Tour,
            'min_participants' => 4,
            'max_participants' => 8,
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenantId,
            'service_id' => $service->id,
            'resource_type' => 'asset',
            'resource_id' => $asset->id,
            'quantity' => 1,
        ]);

        return ['service' => $service, 'asset' => $asset];
    }

    private function rentalOn(string $tenantId): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => 'UTC',
        ]);

        return Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => ServiceKind::Rental,
        ]);
    }

    private function departure(Service $service, CarbonImmutable $start): Departure
    {
        return Departure::query()->create([
            'tenant_id' => $service->tenant_id,
            'service_id' => $service->id,
            'location_id' => $service->location_id,
            'starts_at' => $start,
            'ends_at' => $start->addHours(2),
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
    }

    private function party(Service $service, Departure $departure, BookingStatus $status, string $tenantId): Booking
    {
        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'departure_id' => $departure->id,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $departure->starts_at,
            'ends_at' => $departure->ends_at,
            'participants' => 2,
            'payload_hash' => hash('sha256', $departure->id.$status->value),
        ]);
    }

    /**
     * @return array{prices: int, payments: int, fees: int, refunds: int}
     */
    private function moneyCounts(): array
    {
        return [
            'prices' => PriceRule::query()->count(),
            'payments' => Payment::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'refunds' => Refund::query()->count(),
        ];
    }
}
