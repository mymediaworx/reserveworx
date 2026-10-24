<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Location;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ReportSummary;
use App\Models\Role;
use App\Models\Service;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class OperationsReportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_rates_taxes_and_cash_follow_the_location_day(): void
    {
        $workspace = $this->workspace('dock.test');
        $harbor = $this->location($workspace['tenant']->id, 'UTC', 'Harbor');
        $creek = $this->location($workspace['tenant']->id, 'UTC', 'Creek');
        $kayak = $this->service($workspace['tenant']->id, $harbor->id, 'Kayak');
        $canoe = $this->service($workspace['tenant']->id, $creek->id, 'Canoe');
        $confirmed = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 15:00:00');
        $noShow = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::NoShow, '2026-06-16 19:00:00');
        $cancelled = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Cancelled, '2026-06-16 18:00:00');
        $held = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Held, '2026-06-16 20:00:00');
        $exception = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::PaymentException, '2026-06-16 21:00:00');
        $creekBooking = $this->booking($workspace['tenant']->id, $creek, $canoe, BookingStatus::Confirmed, '2026-06-16 12:00:00');
        $this->snapshot($workspace['tenant']->id, $confirmed, ['tax_cents' => 850, 'fee_cents' => 9_999, 'services_cents' => 10_000]);
        $this->snapshot($workspace['tenant']->id, $noShow, ['tax_cents' => 200, 'fee_cents' => 40]);
        $this->snapshot($workspace['tenant']->id, $cancelled, ['tax_cents' => 111, 'fee_cents' => 70]);
        $this->snapshot($workspace['tenant']->id, $held, ['tax_cents' => 50, 'fee_cents' => 80]);
        $this->snapshot($workspace['tenant']->id, $creekBooking, ['tax_cents' => 300]);
        $card = $this->payment($workspace['tenant']->id, $confirmed, 'stripe', 'paid', 10_300, '2026-06-16 15:00:00');
        $this->payment($workspace['tenant']->id, $confirmed, 'offline', 'paid', 5_000, '2026-06-16 16:00:00');
        $this->payment($workspace['tenant']->id, $confirmed, 'stripe', 'pending', 7_777, '2026-06-16 16:30:00');
        $this->payment($workspace['tenant']->id, $held, 'stripe', 'paid', 4_000, '2026-06-16 20:30:00');
        $this->payment($workspace['tenant']->id, $confirmed, 'stripe', 'paid', 900, '2026-06-15 12:00:00');
        $this->payment($workspace['tenant']->id, $confirmed, 'offline', 'paid', 2_500, '2026-06-18 12:00:00');
        $fee = $this->fee($workspace['tenant']->id, $confirmed, $card, 300, '2026-06-16 15:05:00', 'fee_harbor');
        $this->customerRefund($workspace['tenant']->id, $confirmed, 1_500, 100, '2026-06-16 17:00:00');
        $this->manualRefund($workspace['tenant']->id, $fee, 40, '2026-06-15 18:30:00');
        for ($i = 0; $i < 5; $i++) {
            $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-17 15:0'.$i.':00');
        }
        $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Cancelled, '2026-06-17 18:00:00');

        $this->actingAsWorkspace($workspace);
        $rows = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/operations?from=2026-06-15&to=2026-06-18'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($rows);
        $this->assertCount(5, $rows);
        $indexed = $this->index($rows);
        $this->assertSame([
            'confirmed' => 0,
            'cancelled' => 0,
            'no_show' => 0,
            'cancellation_bps' => null,
            'no_show_bps' => null,
            'card_cents' => 900,
            'offline_cents' => 0,
            'refunds_cents' => 0,
            'tax_cents' => 0,
            'fee_cents' => 0,
            'fee_refunded_cents' => 40,
        ], $this->metrics($indexed['2026-06-15|'.$harbor->id]));
        $this->assertSame([
            'confirmed' => 1,
            'cancelled' => 1,
            'no_show' => 1,
            'cancellation_bps' => 3333,
            'no_show_bps' => 3333,
            'card_cents' => 14300,
            'offline_cents' => 5000,
            'refunds_cents' => 1500,
            'tax_cents' => 1050,
            'fee_cents' => 300,
            'fee_refunded_cents' => 100,
        ], $this->metrics($indexed['2026-06-16|'.$harbor->id]));
        $this->assertSame([
            'confirmed' => 1,
            'cancelled' => 0,
            'no_show' => 0,
            'cancellation_bps' => 0,
            'no_show_bps' => 0,
            'card_cents' => 0,
            'offline_cents' => 0,
            'refunds_cents' => 0,
            'tax_cents' => 300,
            'fee_cents' => 0,
            'fee_refunded_cents' => 0,
        ], $this->metrics($indexed['2026-06-16|'.$creek->id]));
        $this->assertSame([
            'confirmed' => 5,
            'cancelled' => 1,
            'no_show' => 0,
            'cancellation_bps' => 1667,
            'no_show_bps' => 0,
            'card_cents' => 0,
            'offline_cents' => 0,
            'refunds_cents' => 0,
            'tax_cents' => 0,
            'fee_cents' => 0,
            'fee_refunded_cents' => 0,
        ], $this->metrics($indexed['2026-06-17|'.$harbor->id]));
        $this->assertSame([
            'confirmed' => 0,
            'cancelled' => 0,
            'no_show' => 0,
            'cancellation_bps' => null,
            'no_show_bps' => null,
            'card_cents' => 0,
            'offline_cents' => 2500,
            'refunds_cents' => 0,
            'tax_cents' => 0,
            'fee_cents' => 0,
            'fee_refunded_cents' => 0,
        ], $this->metrics($indexed['2026-06-18|'.$harbor->id]));
        $this->assertNotNull($exception->id);
        $this->assertSame(0, ReportSummary::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->count());

        $today = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-06-16'))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($today);
        $this->assertArrayNotHasKey('fee_refunded_cents', $today);
        $this->assertArrayHasKey('outcomes', $today);
    }

    public function test_a_location_date_follows_that_locations_timezone_including_daylight_saving(): void
    {
        $workspace = $this->workspace('dock.test');
        $chicago = $this->location($workspace['tenant']->id, 'America/Chicago', 'Chicago');
        $york = $this->location($workspace['tenant']->id, 'America/New_York', 'New York');
        $kayak = $this->service($workspace['tenant']->id, $chicago->id, 'Kayak');
        $tour = $this->service($workspace['tenant']->id, $york->id, 'Tour');
        $beforeMidnight = $this->booking($workspace['tenant']->id, $chicago, $kayak, BookingStatus::Confirmed, '2026-06-16 04:59:59');
        $atMidnight = $this->booking($workspace['tenant']->id, $chicago, $kayak, BookingStatus::Confirmed, '2026-06-16 05:00:00');
        $this->payment($workspace['tenant']->id, $atMidnight, 'stripe', 'paid', 100, '2026-06-16 04:59:59');
        $this->payment($workspace['tenant']->id, $atMidnight, 'offline', 'paid', 200, '2026-06-16 05:00:00');
        $spring = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::Confirmed, '2026-03-08 04:30:00');
        $springLater = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::Cancelled, '2026-03-08 05:30:00');
        $this->payment($workspace['tenant']->id, $spring, 'stripe', 'paid', 50, '2026-03-08 04:30:00');
        $beforeFallback = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::NoShow, '2026-11-01 03:30:00');
        $duringFallback = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::Confirmed, '2026-11-01 05:30:00');
        $afterFallback = $this->booking($workspace['tenant']->id, $york, $tour, BookingStatus::Confirmed, '2026-11-01 06:30:00');
        $this->payment($workspace['tenant']->id, $beforeFallback, 'stripe', 'paid', 10, '2026-11-01 03:30:00');
        $this->payment($workspace['tenant']->id, $duringFallback, 'stripe', 'paid', 20, '2026-11-01 05:30:00');
        $this->payment($workspace['tenant']->id, $afterFallback, 'offline', 'paid', 30, '2026-11-01 06:30:00');
        $this->snapshot($workspace['tenant']->id, $beforeMidnight, ['tax_cents' => 15]);
        $this->assertNotNull($springLater->id);

        $this->actingAsWorkspace($workspace);
        $summer = $this->index($this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/operations?from=2026-06-15&to=2026-06-16'))
            ->assertOk()
            ->json('data'));
        $this->assertSame(['confirmed' => 1, 'card_cents' => 100, 'offline_cents' => 0, 'tax_cents' => 15], $this->pick($summer['2026-06-15|'.$chicago->id], ['confirmed', 'card_cents', 'offline_cents', 'tax_cents']));
        $this->assertSame(['confirmed' => 1, 'card_cents' => 0, 'offline_cents' => 200, 'tax_cents' => 0], $this->pick($summer['2026-06-16|'.$chicago->id], ['confirmed', 'card_cents', 'offline_cents', 'tax_cents']));

        $march = $this->index($this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/operations?from=2026-03-07&to=2026-03-08'))
            ->assertOk()
            ->json('data'));
        $this->assertSame(['confirmed' => 1, 'cancelled' => 0, 'card_cents' => 50], $this->pick($march['2026-03-07|'.$york->id], ['confirmed', 'cancelled', 'card_cents']));
        $this->assertSame(['confirmed' => 0, 'cancelled' => 1, 'cancellation_bps' => 10000], $this->pick($march['2026-03-08|'.$york->id], ['confirmed', 'cancelled', 'cancellation_bps']));

        $fall = $this->index($this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/operations?from=2026-10-31&to=2026-11-01'))
            ->assertOk()
            ->json('data'));
        $this->assertSame(['no_show' => 1, 'no_show_bps' => 10000, 'card_cents' => 10], $this->pick($fall['2026-10-31|'.$york->id], ['no_show', 'no_show_bps', 'card_cents']));
        $this->assertSame(['confirmed' => 2, 'card_cents' => 20, 'offline_cents' => 30], $this->pick($fall['2026-11-01|'.$york->id], ['confirmed', 'card_cents', 'offline_cents']));
    }

    public function test_csv_rows_match_the_json_rows_and_keep_a_null_rate_blank(): void
    {
        $workspace = $this->workspace('dock.test');
        $harbor = $this->location($workspace['tenant']->id, 'UTC', 'Harbor');
        $kayak = $this->service($workspace['tenant']->id, $harbor->id, 'Kayak');
        $confirmed = $this->booking($workspace['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 15:00:00');
        $this->payment($workspace['tenant']->id, $confirmed, 'offline', 'paid', 2_500, '2026-06-18 12:00:00');

        $this->actingAsWorkspace($workspace);
        $query = 'from=2026-06-16&to=2026-06-18';
        $json = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/operations?'.$query))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($json);
        $csv = $this->get($this->tenantUrl('dock.test', '/api/v1/reports/operations/export?'.$query))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="operations.csv"');
        $rows = $this->table((string) $csv->getContent());
        $this->assertSame([
            'location_id',
            'date',
            'confirmed',
            'cancelled',
            'no_show',
            'cancellation_bps',
            'no_show_bps',
            'card_cents',
            'offline_cents',
            'refunds_cents',
            'tax_cents',
            'fee_cents',
            'fee_refunded_cents',
        ], $rows[0]);
        $this->assertCount(count($json) + 1, $rows);
        foreach ($json as $index => $row) {
            $this->assertIsArray($row);
            $this->assertSame($this->cells([
                $row['location_id'],
                $row['date'],
                $row['confirmed'],
                $row['cancelled'],
                $row['no_show'],
                $row['cancellation_bps'],
                $row['no_show_bps'],
                $row['card_cents'],
                $row['offline_cents'],
                $row['refunds_cents'],
                $row['tax_cents'],
                $row['fee_cents'],
                $row['fee_refunded_cents'],
            ]), $rows[$index + 1]);
        }
        $this->assertSame('0', $rows[1][5]);
        $this->assertSame('', $rows[2][5]);
        $this->assertSame('2500', $rows[2][8]);
        $pairs = array_map(fn (mixed $row): array => is_array($row) ? [$row['date'], $row['location_id']] : [], $json);
        $sorted = $pairs;
        usort($sorted, fn (array $left, array $right): int => $left <=> $right);
        $this->assertSame($sorted, $pairs);
    }

    public function test_another_workspace_is_absent_and_a_bad_window_or_missing_permission_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $staff = $this->workspace('staff.test', 'staff');
        $alphaLocation = $this->location($alpha['tenant']->id, 'UTC', 'Alpha');
        $betaLocation = $this->location($beta['tenant']->id, 'UTC', 'Beta');
        $alphaService = $this->service($alpha['tenant']->id, $alphaLocation->id, 'Kayak');
        $betaService = $this->service($beta['tenant']->id, $betaLocation->id, 'Kayak');
        $alphaBooking = $this->booking($alpha['tenant']->id, $alphaLocation, $alphaService, BookingStatus::Confirmed, '2026-06-16 15:00:00');
        $betaBooking = $this->booking($beta['tenant']->id, $betaLocation, $betaService, BookingStatus::Cancelled, '2026-06-16 15:00:00');
        $this->snapshot($alpha['tenant']->id, $alphaBooking, ['tax_cents' => 100]);
        $this->snapshot($beta['tenant']->id, $betaBooking, ['tax_cents' => 9_000]);
        $this->payment($alpha['tenant']->id, $alphaBooking, 'stripe', 'paid', 1_000, '2026-06-16 15:00:00');
        $this->payment($beta['tenant']->id, $betaBooking, 'stripe', 'paid', 90_000, '2026-06-16 15:00:00');
        $alphaFee = $this->fee($alpha['tenant']->id, $alphaBooking, null, 30, '2026-06-16 15:00:00', 'fee_alpha');
        $betaFee = $this->fee($beta['tenant']->id, $betaBooking, null, 500, '2026-06-16 15:00:00', 'fee_beta');
        $this->customerRefund($alpha['tenant']->id, $alphaBooking, 20, 5, '2026-06-16 16:00:00');
        $this->customerRefund($beta['tenant']->id, $betaBooking, 80_000, 400, '2026-06-16 16:00:00');
        $this->manualRefund($beta['tenant']->id, $betaFee, 25, '2026-06-16 17:00:00');

        $this->actingAsWorkspace($alpha);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/operations?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.location_id', $alphaLocation->id)
            ->assertJsonPath('data.0.confirmed', 1)
            ->assertJsonPath('data.0.cancelled', 0)
            ->assertJsonPath('data.0.tax_cents', 100)
            ->assertJsonPath('data.0.card_cents', 1000)
            ->assertJsonPath('data.0.refunds_cents', 20)
            ->assertJsonPath('data.0.fee_cents', 30)
            ->assertJsonPath('data.0.fee_refunded_cents', 5);
        $alphaCsv = (string) $this->get($this->tenantUrl('alpha.test', '/api/v1/reports/operations/export?from=2026-06-16&to=2026-06-16'))
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString($betaLocation->id, $alphaCsv);
        $this->assertStringNotContainsString('90000', $alphaCsv);
        $this->assertStringNotContainsString('80000', $alphaCsv);
        $this->assertNotNull($alphaFee->id);

        $foreign = $this->getJson($this->tenantUrl('beta.test', '/api/v1/reports/operations?from=2026-06-16&to=2026-06-16'));
        $this->assertContains($foreign->status(), [403, 404]);
        $foreignCsv = $this->getJson($this->tenantUrl('beta.test', '/api/v1/reports/operations/export?from=2026-06-16&to=2026-06-16'));
        $this->assertContains($foreignCsv->status(), [403, 404]);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/operations'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/operations?from=2026-06-17&to=2026-06-16'))->assertStatus(422);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/operations?from=2025-01-01&to=2026-01-02'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/operations/export?from=2025-01-01&to=2026-01-02'))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
        $empty = $this->get($this->tenantUrl('alpha.test', '/api/v1/reports/operations/export?from=2025-01-01&to=2026-01-01'))
            ->assertOk();
        $this->assertCount(1, $this->table((string) $empty->getContent()));
        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/reports/operations?from=2025-01-01&to=2026-01-01'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $permission = Permission::query()->where('slug', 'bookings.view')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->actingAsWorkspace($staff);
        $this->getJson($this->tenantUrl('staff.test', '/api/v1/reports/operations?from=2026-06-16&to=2026-06-16'))
            ->assertForbidden();
        $this->getJson($this->tenantUrl('staff.test', '/api/v1/reports/operations/export?from=2026-06-16&to=2026-06-16'))
            ->assertForbidden();
    }

    private function location(string $tenantId, string $timezone, string $name): Location
    {
        return Location::factory()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    private function service(string $tenantId, string $locationId, string $name): Service
    {
        return Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'name' => $name,
            'kind' => ServiceKind::Rental,
        ]);
    }

    private function booking(
        string $tenantId,
        Location $location,
        Service $service,
        BookingStatus $status,
        string $starts,
    ): Booking {
        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => CarbonImmutable::parse($starts, 'UTC')->addHour()->toDateTimeString(),
            'participants' => 1,
            'payload_hash' => hash('sha256', $tenantId.$starts.$status->value.$location->id.uniqid('', true)),
        ]);
    }

    /**
     * @param  array<string, int>  $payload
     */
    private function snapshot(string $tenantId, Booking $booking, array $payload): void
    {
        PricingSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/11',
            'payload' => $payload,
        ]);
    }

    private function payment(
        string $tenantId,
        Booking $booking,
        string $method,
        string $status,
        int $amount,
        string $at,
    ): Payment {
        $payment = Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'method' => $method,
            'kind' => $method === 'offline' ? 'offline' : 'first_stripe_payment',
            'status' => $status,
            'amount_cents' => $amount,
            'fee_cents' => 0,
            'stripe_payment_intent_id' => $method === 'stripe' ? 'pi_'.hash('sha256', $booking->id.$at.$amount) : null,
        ]);
        $this->stamp($payment, $at);

        return $payment;
    }

    private function fee(
        string $tenantId,
        Booking $booking,
        ?Payment $payment,
        int $amount,
        string $at,
        string $stripeFeeId,
    ): PlatformFee {
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'payment_id' => $payment?->id,
            'amount_cents' => $amount,
            'refunded_cents' => 0,
            'stripe_fee_id' => $stripeFeeId,
            'channel' => 'direct',
        ]);
        $this->stamp($fee, $at);

        return $fee;
    }

    private function customerRefund(
        string $tenantId,
        Booking $booking,
        int $customerCents,
        int $feeCents,
        string $at,
    ): void {
        $refund = Refund::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'reason' => 'operator_cancelled',
            'rules_version' => 'spec-1.0/7.7',
            'customer_refund_cents' => $customerCents,
            'customer_fee_refund_cents' => $feeCents,
            'application_fee_refund_cents' => $feeCents,
            'refund_subtotal_cents' => $customerCents,
            'refund_tax_cents' => 0,
        ]);
        $this->stamp($refund, $at);
    }

    private function manualRefund(string $tenantId, PlatformFee $fee, int $amount, string $at): void
    {
        $refund = PlatformFeeRefund::query()->create([
            'tenant_id' => $tenantId,
            'platform_fee_id' => $fee->id,
            'amount_cents' => $amount,
            'reason' => 'Courtesy',
            'rule' => PlatformFeeRefund::RULE,
        ]);
        $this->stamp($refund, $at);
    }

    private function stamp(Model $model, string $at): void
    {
        $model->forceFill([
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();
    }

    /**
     * @param  list<mixed>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function index(mixed $rows): array
    {
        $this->assertIsArray($rows);
        $indexed = [];

        foreach ($rows as $row) {
            $this->assertIsArray($row);
            $date = $row['date'] ?? null;
            $locationId = $row['location_id'] ?? null;
            $this->assertIsString($date);
            $this->assertIsString($locationId);
            $indexed[$date.'|'.$locationId] = $row;
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function pick(array $row, array $keys): array
    {
        $picked = [];

        foreach ($keys as $key) {
            $picked[$key] = $row[$key] ?? null;
        }

        return $picked;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function metrics(array $row): array
    {
        return $this->pick($row, [
            'confirmed',
            'cancelled',
            'no_show',
            'cancellation_bps',
            'no_show_bps',
            'card_cents',
            'offline_cents',
            'refunds_cents',
            'tax_cents',
            'fee_cents',
            'fee_refunded_cents',
        ]);
    }

    /**
     * @param  list<int|string|null>  $values
     * @return list<string>
     */
    private function cells(array $values): array
    {
        return array_map(
            fn (int|string|null $value): string => $value === null ? '' : (string) $value,
            $values,
        );
    }

    /**
     * @return list<list<string>>
     */
    private function table(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $cells = [];

            foreach ($row as $cell) {
                $cells[] = is_string($cell) ? $cell : '';
            }

            $rows[] = $cells;
        }

        fclose($handle);

        return $rows;
    }
}
