<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Location;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Refund;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TodayFeeRefundTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_booking_fee_refunds_follow_the_location_date_and_leave_net_all_time(): void
    {
        $workspace = $this->workspace('dock.test');
        $harbor = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Harbor',
            'timezone' => 'America/Chicago',
        ]);
        $creek = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Creek',
            'timezone' => 'America/New_York',
        ]);
        $kayak = $this->service($workspace['tenant']->id, $harbor->id, 'Kayak');
        $tour = $this->service($workspace['tenant']->id, $creek->id, 'Tour');

        $today = $this->booking($workspace['tenant']->id, $harbor->id, $kayak->id, 'harbor-today');
        $yesterday = $this->booking($workspace['tenant']->id, $harbor->id, $kayak->id, 'harbor-yesterday');
        $old = $this->booking($workspace['tenant']->id, $harbor->id, $kayak->id, 'harbor-old');
        $noFee = $this->booking($workspace['tenant']->id, $harbor->id, $kayak->id, 'harbor-no-fee');
        $york = $this->booking($workspace['tenant']->id, $creek->id, $tour->id, 'york');

        $todayFee = $this->fee($workspace['tenant']->id, $today->id, 300, 125);
        $yesterdayFee = $this->fee($workspace['tenant']->id, $yesterday->id, 200, 47);
        $this->fee($workspace['tenant']->id, $old->id, 5_000, 4_000);
        $this->fee($workspace['tenant']->id, $noFee->id, 50, 0);
        $this->fee($workspace['tenant']->id, $york->id, 800, 70);

        $this->sectionRefund($workspace['tenant']->id, $today->id, 100, '2026-06-16 05:00:00');
        $this->sectionRefund($workspace['tenant']->id, $yesterday->id, 40, '2026-06-16 04:59:59');
        $this->sectionRefund($workspace['tenant']->id, $old->id, 4_000, '2026-01-15 12:00:00');
        $this->sectionRefund($workspace['tenant']->id, $noFee->id, 0, '2026-06-16 18:00:00', 80);
        $this->sectionRefund($workspace['tenant']->id, $york->id, 70, '2026-06-16 04:30:00');
        $this->manualRefund($workspace['tenant']->id, $todayFee, 25, '2026-06-16 18:00:00');
        $this->manualRefund($workspace['tenant']->id, $yesterdayFee, 7, '2026-06-15 18:00:00');

        $other = $this->workspace('other.test');
        $otherLocation = Location::factory()->create([
            'tenant_id' => $other['tenant']->id,
            'name' => 'Other',
            'timezone' => 'America/Chicago',
        ]);
        $otherService = $this->service($other['tenant']->id, $otherLocation->id, 'Other');
        $otherBooking = $this->booking($other['tenant']->id, $otherLocation->id, $otherService->id, 'other');
        $this->fee($other['tenant']->id, $otherBooking->id, 9_000, 9_000);
        $this->sectionRefund($other['tenant']->id, $otherBooking->id, 9_000, '2026-06-16 18:00:00');

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-06-16'))
            ->assertOk()
            ->assertJsonPath('data.fees.refunded_cents', 195)
            ->assertJsonPath('data.fees.charged_cents', 6350)
            ->assertJsonPath('data.fees.net_cents', 2108)
            ->assertJsonPath('data.fees.period', 'all_time');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-06-15'))
            ->assertOk()
            ->assertJsonPath('data.fees.refunded_cents', 47)
            ->assertJsonPath('data.fees.charged_cents', 6350)
            ->assertJsonPath('data.fees.net_cents', 2108);

        $csv = $this->get($this->tenantUrl('dock.test', '/api/v1/reports/today/export?date=2026-06-16'))
            ->assertOk()
            ->getContent();
        $this->assertIsString($csv);
        $this->assertSame(['fee', 'refunded', '', '', '2026-06-16', '', '195'], $this->csvRow($csv, 'fee', 'refunded'));
        $this->assertSame(['fee', 'net', '', '', 'all_time', '', '2108'], $this->csvRow($csv, 'fee', 'net'));
    }

    public function test_booking_fee_refunds_follow_a_location_through_daylight_saving(): void
    {
        $workspace = $this->workspace('dock.test');
        $york = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'New York',
            'timezone' => 'America/New_York',
        ]);
        $tour = $this->service($workspace['tenant']->id, $york->id, 'Tour');

        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 50, '2026-03-08 04:59:59', 'spring-before');
        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 60, '2026-03-08 05:00:00', 'spring-midnight');
        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 15, '2026-03-09 03:59:59', 'spring-end');
        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 90, '2026-03-09 04:00:00', 'spring-next');
        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 11, '2026-11-01 03:59:59', 'fall-before');
        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 21, '2026-11-01 04:00:00', 'fall-midnight');
        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 31, '2026-11-01 06:30:00', 'fall-repeat');
        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 41, '2026-11-02 04:59:59', 'fall-end');
        $this->datedRefund($workspace['tenant']->id, $york->id, $tour->id, 51, '2026-11-02 05:00:00', 'fall-next');

        $this->actingAsWorkspace($workspace);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-03-08'))
            ->assertOk()
            ->assertJsonPath('data.fees.refunded_cents', 75);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/today?date=2026-11-01'))
            ->assertOk()
            ->assertJsonPath('data.fees.refunded_cents', 93);
    }

    private function datedRefund(string $tenantId, string $locationId, string $serviceId, int $cents, string $createdAt, string $label): void
    {
        $booking = $this->booking($tenantId, $locationId, $serviceId, $label);
        $this->fee($tenantId, $booking->id, $cents, $cents);
        $this->sectionRefund($tenantId, $booking->id, $cents, $createdAt);
    }

    private function service(string $tenantId, string $locationId, string $name): Service
    {
        return Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'name' => $name,
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
    }

    private function booking(string $tenantId, string $locationId, string $serviceId, string $label): Booking
    {
        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'service_id' => $serviceId,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 1,
            'payload_hash' => hash('sha256', $label),
        ]);
    }

    private function fee(string $tenantId, string $bookingId, int $amount, int $refunded): PlatformFee
    {
        return PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'amount_cents' => $amount,
            'refunded_cents' => $refunded,
            'channel' => 'direct',
        ]);
    }

    private function sectionRefund(string $tenantId, string $bookingId, int $applicationFeeCents, string $createdAt, int $customerFeeCents = 0): void
    {
        $refund = Refund::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $bookingId,
            'reason' => 'operator_cancelled',
            'rules_version' => 'spec-1.0/7.7',
            'customer_refund_cents' => $applicationFeeCents,
            'customer_fee_refund_cents' => $customerFeeCents,
            'application_fee_refund_cents' => $applicationFeeCents,
            'refund_subtotal_cents' => 0,
            'refund_tax_cents' => 0,
        ]);
        $refund->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }

    private function manualRefund(string $tenantId, PlatformFee $fee, int $amount, string $createdAt): void
    {
        $refund = PlatformFeeRefund::query()->create([
            'tenant_id' => $tenantId,
            'platform_fee_id' => $fee->id,
            'amount_cents' => $amount,
            'reason' => 'Weather goodwill',
            'rule' => PlatformFeeRefund::RULE,
        ]);
        $refund->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
    }

    /**
     * @return list<string>
     */
    private function csvRow(string $csv, string $record, string $id): array
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);

        while (($row = fgetcsv($handle)) !== false) {
            if (($row[0] ?? null) === $record && ($row[1] ?? null) === $id) {
                fclose($handle);

                return array_map(fn (mixed $cell): string => is_string($cell) ? $cell : '', $row);
            }
        }

        fclose($handle);
        $this->fail('Missing '.$record.' '.$id.' row.');
    }
}
