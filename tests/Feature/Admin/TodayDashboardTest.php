<?php

namespace Tests\Feature\Admin;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\Service;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TodayDashboardTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_today_lists_local_bookings_and_the_staff_page_requires_a_member(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Harbor',
            'timezone' => 'America/Chicago',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak',
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
        $today = $this->booking($workspace['tenant']->id, $location->id, $service->id, '2026-06-16 15:00:00', 'Engine service');
        $this->booking($workspace['tenant']->id, $location->id, $service->id, '2026-06-16 04:00:00', null);
        Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => BookingStatus::Cancelled,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 17:00:00',
            'ends_at' => '2026-06-16 18:00:00',
            'participants' => 1,
            'payload_hash' => str_repeat('b', 64),
        ]);
        Departure::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'service_id' => $service->id,
            'location_id' => $location->id,
            'starts_at' => '2026-06-16 16:00:00',
            'ends_at' => '2026-06-16 18:00:00',
            'private' => false,
            'seats' => 8,
            'status' => DepartureStatus::Scheduled,
        ]);
        RentalCheckout::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $today->id,
            'quantity' => 1,
            'checked_out_at' => '2026-06-16 15:00:00',
            'due_at' => '2026-06-16 16:00:00',
        ]);
        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $today->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 10300,
            'fee_cents' => 300,
        ]);
        Payment::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $today->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 5000,
            'fee_cents' => 0,
        ]);
        PlatformFee::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $today->id,
            'amount_cents' => 300,
            'refunded_cents' => 100,
            'channel' => 'direct',
        ]);
        PricingSnapshot::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $today->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'payload' => [
                'services_cents' => 10_000,
                'tax_cents' => 800,
                'fee_cents' => 300,
            ],
        ]);
        Refund::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'booking_id' => $today->id,
            'reason' => 'operator_cancelled',
            'rules_version' => 'spec-1.0/7.7',
            'customer_refund_cents' => 1_500,
            'customer_fee_refund_cents' => 0,
            'application_fee_refund_cents' => 100,
            'refund_subtotal_cents' => 1_400,
            'refund_tax_cents' => 100,
        ]);

        $this->get($this->tenantUrl('dock.test', '/admin'))->assertRedirect('/staff');

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today'))
            ->assertOk()
            ->assertJsonPath('data.date', '2026-06-16')
            ->assertJsonPath('data.timezone', 'America/Chicago')
            ->assertJsonPath('data.bookings.0.id', $today->id)
            ->assertJsonPath('data.bookings.0.service', 'Kayak')
            ->assertJsonPath('data.bookings.0.attention_reason', 'Engine service')
            ->assertJsonCount(1, 'data.bookings')
            ->assertJsonCount(1, 'data.departures')
            ->assertJsonPath('data.departures.0.seats', 8)
            ->assertJsonPath('data.open_checkouts', 1)
            ->assertJsonPath('data.overdue_checkouts', 1)
            ->assertJsonPath('data.payments.stripe_cents', 10300)
            ->assertJsonPath('data.payments.offline_cents', 5000)
            ->assertJsonPath('data.totals.gross_cents', 10000)
            ->assertJsonPath('data.totals.tax_cents', 800)
            ->assertJsonPath('data.totals.booking_fee_cents', 300)
            ->assertJsonPath('data.totals.refunds_cents', 1500)
            ->assertJsonPath('data.fees.charged_cents', 300)
            ->assertJsonPath('data.fees.refunded_cents', 100)
            ->assertJsonPath('data.fees.net_cents', 200)
            ->assertJsonPath('data.fees.period', 'all_time')
            ->assertJsonPath('data.outcomes.confirmed', 1)
            ->assertJsonPath('data.outcomes.cancelled', 1)
            ->assertJsonPath('data.outcomes.no_show', 0)
            ->assertJsonPath('data.outcomes.cancellation_bps', 5000)
            ->assertJsonPath('data.outcomes.no_show_bps', 0);

        $this->get($this->tenantUrl('dock.test', '/api/v1/reports/today/export'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertSee('Kayak')
            ->assertSee('Engine service')
            ->assertSee('10300');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-02-31'))
            ->assertStatus(422);

        $this->flushSession();
        $this->post($this->tenantUrl('dock.test', '/admin/login'), [
            'email' => $workspace['user']->email,
            'password' => 'password',
        ])->assertRedirect(route('staff.home'));

        $this->get($this->tenantUrl('dock.test', '/admin'))->assertRedirect('/staff');
        $this->get($this->tenantUrl('dock.test', '/staff'))
            ->assertOk()
            ->assertSee('Kayak');

        $other = $this->workspace('other.test');
        $this->post($this->tenantUrl('other.test', '/admin/login'), [
            'email' => $workspace['user']->email,
            'password' => 'password',
        ])->assertRedirect('/staff/login')
            ->assertSessionHasErrors('email');

        $this->flushSession();
        $this->actingAs($other['user']);
        $this->get($this->tenantUrl('dock.test', '/admin'))->assertRedirect('/staff');
        $this->get($this->tenantUrl('dock.test', '/staff'))->assertForbidden();
        $this->get($this->tenantUrl('localhost', '/admin'))->assertNotFound();
    }

    private function booking(string $tenantId, string $locationId, string $serviceId, string $startsAt, ?string $attention): Booking
    {
        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'service_id' => $serviceId,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => Carbon::parse($startsAt, 'UTC')->addHour()->toDateTimeString(),
            'participants' => 2,
            'attention_reason' => $attention,
            'payload_hash' => hash('sha256', $startsAt),
        ]);
    }
}
