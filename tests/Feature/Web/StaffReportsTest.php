<?php

namespace Tests\Feature\Web;

use App\Domain\Fees\Rounding;
use App\Enums\BookingStatus;
use App\Enums\Industry;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\ReportSummary;
use App\Models\Role;
use App\Models\Service;
use App\Tenancy\StaffLocationAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffReportsTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_page_renders_summary_and_booking_figures_for_a_range_and_csv_matches(): void
    {
        $alpha = $this->workspace('alpha.test', 'owner', ['name' => 'Alpha Kayaks']);
        $beta = $this->workspace('beta.test', 'owner', ['name' => 'Beta Boats']);
        $harbor = $this->location($alpha['tenant']->id, 'UTC', 'Harbor');
        $creek = $this->location($alpha['tenant']->id, 'UTC', 'Creek');
        $foreign = $this->location($beta['tenant']->id, 'UTC', 'Foreign dock');
        $kayak = $this->service($alpha['tenant']->id, $harbor->id, 'Kayak');
        $canoe = $this->service($alpha['tenant']->id, $creek->id, 'Canoe');
        $secret = $this->service($beta['tenant']->id, $foreign->id, 'Secret paddle');

        $ada = $this->customer($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com');
        $bea = $this->customer($alpha['tenant']->id, 'Bea New', 'bea@example.com');
        $dee = $this->customer($alpha['tenant']->id, 'Dee Creek', 'dee@example.com');
        $spy = $this->customer($beta['tenant']->id, 'Secret Gamma', 'secret@example.com');

        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-01 15:00:00', $ada->id, ['services_cents' => 111], 'direct');
        $adaVisit = $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 15:00:00', $ada->id, ['services_cents' => 6_000, 'tax_cents' => 800], 'direct');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 16:00:00', $bea->id, ['services_cents' => 4_000], 'direct');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::NoShow, '2026-06-17 15:00:00', $ada->id, ['basis_cents' => 4_000, 'tax_cents' => 200], 'third_party');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Cancelled, '2026-06-16 18:00:00', $ada->id, ['services_cents' => 8_000, 'tax_cents' => 111], 'direct');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Held, '2026-06-16 20:00:00', $bea->id, ['services_cents' => 1_111], 'direct');
        $this->booking($alpha['tenant']->id, $creek, $canoe, BookingStatus::Confirmed, '2026-06-16 12:00:00', $dee->id, ['services_cents' => 7_000, 'tax_cents' => 300], 'direct');
        $eve = $this->customer($alpha['tenant']->id, 'Eve Early', 'eve@example.com');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-11 15:00:00', $eve->id, ['services_cents' => 3_333], 'direct');
        $this->booking($beta['tenant']->id, $foreign, $secret, BookingStatus::Confirmed, '2026-06-16 15:00:00', $spy->id, ['services_cents' => 99_999], 'direct');

        $card = $this->payment($alpha['tenant']->id, $adaVisit, 'stripe', 10_800, '2026-06-16 16:00:00');
        $this->payment($alpha['tenant']->id, $adaVisit, 'offline', 2_000, '2026-06-16 16:30:00');
        $fee = $this->fee($alpha['tenant']->id, $adaVisit, $card, 300, '2026-06-16 16:05:00');
        $this->customerRefund($alpha['tenant']->id, $adaVisit, 1_500, 40, '2026-06-16 17:00:00');
        $this->manualRefund($alpha['tenant']->id, $fee, 15, '2026-06-16 18:00:00');

        $this->summary($harbor, '2026-06-01', 60, 60, 1, 1, 111, 99_999);
        $this->summary($harbor, '2026-06-16', 120, 60, 4, 2, 5_000, 8_000);
        $this->summary($harbor, '2026-06-17', 60, 0, 2, 1, 4_000, 4_000);
        $this->summary($creek, '2026-06-16', 100, 50, 1, 1, 7_000, 7_000);
        $this->summary($foreign, '2026-06-16', 60, 60, 1, 1, 99_999, 99_999);

        $utilization = Rounding::halfUp(110 * 10_000, 280);
        $conversion = Rounding::halfUp(4 * 10_000, 7);
        $average = Rounding::halfUp((5_000 * 2) + 4_000 + 7_000, 4);
        $perHour = Rounding::halfUp(19_000 * 60, 280);
        $harborUtilization = Rounding::halfUp(60 * 10_000, 180);
        $harborAverage = Rounding::halfUp((5_000 * 2) + 4_000, 3);
        $harborPerHour = Rounding::halfUp(12_000 * 60, 180);

        $url = '/staff/reports?from=2026-06-15&to=2026-06-18';
        $page = $this->actingAs($alpha['user'])->get($this->tenantUrl('alpha.test', $url));
        $page->assertOk()
            ->assertSee('Reports')
            ->assertSee('Gross booking value')
            ->assertSee('Card payments')
            ->assertSee('Offline payments')
            ->assertSee('Booking fees charged')
            ->assertSee('Booking fees refunded')
            ->assertSee('It is not the money captured on a card')
            ->assertSee('Kayak')
            ->assertSee('Canoe')
            ->assertSee('Harbor')
            ->assertSee('Creek')
            ->assertSee('Revenue per available boat-hour')
            ->assertSee('$210.00')
            ->assertSee('$108.00')
            ->assertSee('$20.00')
            ->assertSee('$15.00')
            ->assertSee('$13.00')
            ->assertSee('$3.00')
            ->assertSee('$0.55')
            ->assertSee('20.0%')
            ->assertSee('Direct')
            ->assertSee('Third party')
            ->assertSee('href="/staff/reports"', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('Secret paddle')
            ->assertDontSee('Secret Gamma')
            ->assertDontSee('99999')
            ->assertDontSee('$111.11');

        $html = (string) $page->getContent();
        $this->assertSame('21000', $this->figure($html, 'gross'));
        $this->assertSame('10800', $this->figure($html, 'card'));
        $this->assertSame('2000', $this->figure($html, 'offline'));
        $this->assertSame('1500', $this->figure($html, 'refunds'));
        $this->assertSame('1300', $this->figure($html, 'tax'));
        $this->assertSame('300', $this->figure($html, 'fee'));
        $this->assertSame('55', $this->figure($html, 'fee-refunded'));
        $this->assertSame('2000', $this->figure($html, 'cancellation'));
        $this->assertSame('2000', $this->figure($html, 'no-show'));
        $this->assertSame('2', $this->figure($html, 'new-customers'));
        $this->assertSame('1', $this->figure($html, 'repeat-customers'));
        $this->assertSame('ready', $this->figure($html, 'summary'));
        $this->assertSame((string) $utilization, $this->figure($html, 'utilization'));
        $this->assertSame((string) $conversion, $this->figure($html, 'conversion'));
        $this->assertSame((string) $average, $this->figure($html, 'average-order'));
        $this->assertSame((string) $perHour, $this->figure($html, 'revenue-per-hour'));
        $this->assertNotSame('21000', $this->figure($html, 'card'));
        $this->assertStringContainsString('data-service="Kayak" data-service-gross="14000"', $html);
        $this->assertStringContainsString('data-service="Canoe" data-service-gross="7000"', $html);
        $this->assertStringContainsString('data-date="2026-06-16" data-date-gross="17000"', $html);
        $this->assertStringContainsString('data-date="2026-06-17" data-date-gross="4000"', $html);
        $this->assertStringContainsString('data-channel="direct" data-channel-bookings="3"', $html);
        $this->assertStringContainsString('data-channel="third_party" data-channel-bookings="1"', $html);

        $csv = $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-15&to=2026-06-18'));
        $csv->assertOk();
        $csv->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $csv->getContent();
        $this->assertIsString($body);
        $records = $this->records($body);
        $total = $this->record($records, 'total');
        $this->assertSame($this->figure($html, 'gross'), $total['gross_cents']);
        $this->assertSame($this->figure($html, 'card'), $total['card_cents']);
        $this->assertSame($this->figure($html, 'offline'), $total['offline_cents']);
        $this->assertSame($this->figure($html, 'refunds'), $total['refunds_cents']);
        $this->assertSame($this->figure($html, 'tax'), $total['tax_cents']);
        $this->assertSame($this->figure($html, 'fee'), $total['fee_charged_cents']);
        $this->assertSame($this->figure($html, 'fee-refunded'), $total['fee_refunded_cents']);
        $this->assertSame($this->figure($html, 'cancellation'), $total['cancellation_bps']);
        $this->assertSame($this->figure($html, 'no-show'), $total['no_show_bps']);
        $this->assertSame($this->figure($html, 'new-customers'), $total['new_customers']);
        $this->assertSame($this->figure($html, 'repeat-customers'), $total['repeat_customers']);
        $this->assertSame($this->figure($html, 'utilization'), $total['utilization_bps']);
        $this->assertSame($this->figure($html, 'conversion'), $total['conversion_bps']);
        $this->assertSame($this->figure($html, 'average-order'), $total['average_order_cents']);
        $this->assertSame($this->figure($html, 'revenue-per-hour'), $total['revenue_per_resource_hour_cents']);
        $this->assertSame('14000', $this->record($records, 'revenue_by_service', 'Kayak')['gross_cents']);
        $this->assertSame('7000', $this->record($records, 'revenue_by_service', 'Canoe')['gross_cents']);
        $this->assertSame('17000', $this->record($records, 'revenue_by_date', '2026-06-16')['gross_cents']);
        $this->assertSame('3', $this->record($records, 'attribution', 'direct')['bookings']);
        $this->assertStringNotContainsString('99999', $body);
        $this->assertStringNotContainsString('Secret paddle', $body);

        $harborPage = $this->get($this->tenantUrl('alpha.test', $url.'&location='.$harbor->id));
        $harborPage->assertOk()
            ->assertSee('Kayak')
            ->assertSee('$140.00')
            ->assertSee('$108.00')
            ->assertSee('$10.00')
            ->assertSee('25.0%')
            ->assertSee('value="'.$harbor->id.'" selected', false)
            ->assertDontSee('Canoe')
            ->assertDontSee('$210.00')
            ->assertDontSee('$70.00')
            ->assertDontSee('99999');
        $harborHtml = (string) $harborPage->getContent();
        $this->assertSame('14000', $this->figure($harborHtml, 'gross'));
        $this->assertSame('1000', $this->figure($harborHtml, 'tax'));
        $this->assertSame('2500', $this->figure($harborHtml, 'cancellation'));
        $this->assertSame('2500', $this->figure($harborHtml, 'no-show'));
        $this->assertSame('1', $this->figure($harborHtml, 'new-customers'));
        $this->assertSame('1', $this->figure($harborHtml, 'repeat-customers'));
        $this->assertSame((string) $harborUtilization, $this->figure($harborHtml, 'utilization'));
        $this->assertSame('5000', $this->figure($harborHtml, 'conversion'));
        $this->assertSame((string) $harborAverage, $this->figure($harborHtml, 'average-order'));
        $this->assertSame((string) $harborPerHour, $this->figure($harborHtml, 'revenue-per-hour'));
        $this->assertStringContainsString('data-date="2026-06-16" data-date-gross="10000"', $harborHtml);

        $harborCsv = (string) $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-15&to=2026-06-18&location='.$harbor->id))
            ->assertOk()
            ->getContent();
        $harborTotal = $this->record($this->records($harborCsv), 'total');
        $this->assertSame('14000', $harborTotal['gross_cents']);
        $this->assertSame((string) $harborPerHour, $harborTotal['revenue_per_resource_hour_cents']);
        $this->assertStringNotContainsString('Canoe', $harborCsv);
        $this->assertStringNotContainsString('7000', $harborCsv);

        $empty = $this->get($this->tenantUrl('alpha.test', '/staff/reports?from=2026-06-11&to=2026-06-11'));
        $empty->assertOk()
            ->assertSee('Daily summaries have not been built for this range yet.')
            ->assertSee('boat-hour')
            ->assertSee('$33.33')
            ->assertDontSee('$210.00')
            ->assertDontSee('Revenue per available boat-hour');
        $emptyHtml = (string) $empty->getContent();
        $this->assertSame('3333', $this->figure($emptyHtml, 'gross'));
        $this->assertSame('empty', $this->figure($emptyHtml, 'summary'));
        $this->assertSame('', $this->figure($emptyHtml, 'utilization'));
        $emptyCsv = $this->record($this->records((string) $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-11&to=2026-06-11'))->assertOk()->getContent()), 'total');
        $this->assertSame('3333', $emptyCsv['gross_cents']);
        $this->assertSame('', $emptyCsv['utilization_bps']);
        $this->assertSame('', $emptyCsv['revenue_per_resource_hour_cents']);

        $this->get($this->tenantUrl('alpha.test', '/staff/reports?from=2026-06-18&to=2026-06-15'))->assertStatus(422);
        $this->get($this->tenantUrl('alpha.test', '/staff/reports?from=2025-01-01&to=2026-02-01'))->assertStatus(422);
        $this->get($this->tenantUrl('alpha.test', '/staff/reports?from=2026-02-31&to=2026-03-01'))->assertStatus(422);
        $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-18&to=2026-06-15'))->assertStatus(422);
        $this->get($this->tenantUrl('alpha.test', $url.'&location='.$foreign->id))->assertNotFound()->assertDontSee('99999');
        $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-15&to=2026-06-18&location='.$foreign->id))
            ->assertNotFound();

        $this->actingAs($beta['user'])
            ->get($this->tenantUrl('beta.test', $url))
            ->assertOk()
            ->assertSee('Secret paddle')
            ->assertSee('$999.99')
            ->assertDontSee('Kayak')
            ->assertDontSee('Ada Lovelace')
            ->assertDontSee('$210.00')
            ->assertDontSee('$108.00');
    }

    public function test_a_location_limited_member_only_sees_their_locations(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $beta = $this->workspace('beta.test');
        $harbor = $this->location($alpha['tenant']->id, 'UTC', 'Harbor');
        $creek = $this->location($alpha['tenant']->id, 'UTC', 'Creek');
        $foreign = $this->location($beta['tenant']->id, 'UTC', 'Foreign dock');
        $kayak = $this->service($alpha['tenant']->id, $harbor->id, 'Kayak');
        $canoe = $this->service($alpha['tenant']->id, $creek->id, 'Canoe');
        $secret = $this->service($beta['tenant']->id, $foreign->id, 'Secret paddle');
        $ada = $this->customer($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com');
        $dee = $this->customer($alpha['tenant']->id, 'Dee Creek', 'dee@example.com');
        $spy = $this->customer($beta['tenant']->id, 'Secret Gamma', 'secret@example.com');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-16 15:00:00', $ada->id, ['services_cents' => 10_000], 'direct');
        $this->booking($alpha['tenant']->id, $creek, $canoe, BookingStatus::Confirmed, '2026-06-16 12:00:00', $dee->id, ['services_cents' => 7_000], 'direct');
        $this->booking($beta['tenant']->id, $foreign, $secret, BookingStatus::Confirmed, '2026-06-16 15:00:00', $spy->id, ['services_cents' => 99_999], 'direct');
        $this->summary($harbor, '2026-06-16', 120, 60, 1, 1, 5_000, 8_000);
        $this->summary($creek, '2026-06-16', 100, 50, 1, 1, 7_000, 7_000);
        $this->summary($foreign, '2026-06-16', 60, 60, 1, 1, 99_999, 99_999);
        $this->limit($alpha['membership'], $harbor->id);

        $url = '/staff/reports?from=2026-06-15&to=2026-06-18';
        $page = $this->actingAs($alpha['user'])->get($this->tenantUrl('alpha.test', $url));
        $page->assertOk()
            ->assertSee('Kayak')
            ->assertSee('Harbor')
            ->assertSee('$100.00')
            ->assertDontSee('Canoe')
            ->assertDontSee('Creek')
            ->assertDontSee('$70.00')
            ->assertDontSee('Secret paddle')
            ->assertDontSee('Secret Gamma')
            ->assertDontSee('99999');
        $html = (string) $page->getContent();
        $this->assertSame('10000', $this->figure($html, 'gross'));
        $this->assertSame((string) Rounding::halfUp(60 * 10_000, 120), $this->figure($html, 'utilization'));
        $this->assertSame((string) Rounding::halfUp(8_000 * 60, 120), $this->figure($html, 'revenue-per-hour'));
        $this->assertStringNotContainsString($creek->id, $html);
        $this->assertStringNotContainsString($foreign->id, $html);

        $csv = (string) $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-15&to=2026-06-18'))
            ->assertOk()
            ->getContent();
        $this->assertSame('10000', $this->record($this->records($csv), 'total')['gross_cents']);
        $this->assertStringNotContainsString('Canoe', $csv);
        $this->assertStringNotContainsString('7000', $csv);
        $this->assertStringNotContainsString('99999', $csv);

        $this->get($this->tenantUrl('alpha.test', $url.'&location='.$creek->id))->assertNotFound()->assertDontSee('$70.00');
        $this->get($this->tenantUrl('alpha.test', '/staff/reports.csv?from=2026-06-15&to=2026-06-18&location='.$creek->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', $url.'&location='.$foreign->id))->assertNotFound()->assertDontSee('99999');
    }

    public function test_a_member_without_the_reports_permission_is_refused(): void
    {
        $staff = $this->workspace('staff.test', 'staff');
        $this->location($staff['tenant']->id, 'UTC', 'Harbor');
        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id);

        $this->actingAs($staff['user']);
        $this->get($this->tenantUrl('staff.test', '/staff/reports'))->assertForbidden();
        $this->get($this->tenantUrl('staff.test', '/staff/reports.csv'))->assertForbidden();
    }

    public function test_the_default_range_is_the_last_30_days_in_the_location_timezone(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-21 03:30:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $harbor = $this->location($alpha['tenant']->id, 'America/New_York', 'Harbor');
        $kayak = $this->service($alpha['tenant']->id, $harbor->id, 'Kayak');
        $ada = $this->customer($alpha['tenant']->id, 'Ada Lovelace', 'ada@example.com');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-20 15:00:00', $ada->id, ['services_cents' => 1_234], 'direct');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-06-21 15:00:00', $ada->id, ['services_cents' => 7_777], 'direct');
        $this->booking($alpha['tenant']->id, $harbor, $kayak, BookingStatus::Confirmed, '2026-05-21 15:00:00', $ada->id, ['services_cents' => 6_666], 'direct');
        $this->summary($harbor, '2026-06-20', 60, 30, 1, 1, 1_000, 6_000);
        $this->summary($harbor, '2026-06-21', 60, 60, 1, 1, 99_999, 99_999);
        $this->summary($harbor, '2026-05-21', 60, 60, 1, 1, 88_888, 88_888);

        $page = $this->actingAs($alpha['user'])->get($this->tenantUrl('alpha.test', '/staff/reports'));
        $page->assertOk()
            ->assertSee('value="2026-05-22"', false)
            ->assertSee('value="2026-06-20"', false)
            ->assertSee('$12.34')
            ->assertDontSee('$77.77')
            ->assertDontSee('$66.66')
            ->assertDontSee('99999')
            ->assertDontSee('88888');
        $html = (string) $page->getContent();
        $this->assertSame('1234', $this->figure($html, 'gross'));
        $this->assertSame('5000', $this->figure($html, 'utilization'));
        $this->assertSame('10000', $this->figure($html, 'conversion'));
        $this->assertSame('1000', $this->figure($html, 'average-order'));
        $this->assertSame('6000', $this->figure($html, 'revenue-per-hour'));
    }

    public function test_another_industry_uses_its_own_word_for_a_resource_hour(): void
    {
        $spa = $this->workspace('spa.test', 'owner', ['industry' => Industry::SpasWellness->value]);
        $this->location($spa['tenant']->id, 'UTC', 'Studio');

        $this->actingAs($spa['user'])
            ->get($this->tenantUrl('spa.test', '/staff/reports?from=2026-06-01&to=2026-06-01'))
            ->assertOk()
            ->assertSee('room-hour')
            ->assertDontSee('boat-hour');
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

    private function customer(string $tenantId, string $name, string $email): Customer
    {
        return Customer::factory()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => $email,
        ]);
    }

    /**
     * @param  array<string, int|string>  $payload
     */
    private function booking(
        string $tenantId,
        Location $location,
        Service $service,
        BookingStatus $status,
        string $starts,
        string $customerId,
        array $payload,
        string $channel,
    ): Booking {
        $booking = Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customerId,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => CarbonImmutable::parse($starts, 'UTC')->addHour()->toDateTimeString(),
            'participants' => 1,
            'payload_hash' => hash('sha256', $tenantId.$starts.$status->value.$customerId.uniqid('', true)),
        ]);
        PricingSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/11',
            'payload' => ['channel' => $channel, ...$payload],
        ]);

        return $booking;
    }

    private function payment(string $tenantId, Booking $booking, string $method, int $amount, string $at): Payment
    {
        $payment = Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'method' => $method,
            'kind' => $method === 'offline' ? 'offline' : 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => $amount,
            'fee_cents' => 0,
            'stripe_payment_intent_id' => $method === 'stripe' ? 'pi_'.hash('sha256', $booking->id.$at.$amount) : null,
        ]);
        $this->stamp($payment, $at);

        return $payment;
    }

    private function fee(string $tenantId, Booking $booking, Payment $payment, int $amount, string $at): PlatformFee
    {
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => $amount,
            'refunded_cents' => 0,
            'stripe_fee_id' => 'fee_'.hash('sha256', $booking->id.$at),
            'channel' => 'direct',
        ]);
        $this->stamp($fee, $at);

        return $fee;
    }

    private function customerRefund(string $tenantId, Booking $booking, int $customerCents, int $feeCents, string $at): void
    {
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

    private function summary(
        Location $location,
        string $date,
        int $available,
        int $allocated,
        int $created,
        int $converted,
        int $average,
        int $gross,
    ): void {
        ReportSummary::query()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'summary_date' => $date,
            'available_resource_minutes' => $available,
            'allocated_resource_minutes' => $allocated,
            'bookings_created' => $created,
            'bookings_converted' => $converted,
            'average_order_cents' => $average,
            'gross_cents' => $gross,
        ]);
    }

    private function limit(Membership $membership, string ...$locationIds): void
    {
        $membership->locations_restricted = true;
        $membership->save();

        foreach ($locationIds as $locationId) {
            MembershipLocation::query()->create([
                'tenant_id' => $membership->tenant_id,
                'membership_id' => $membership->id,
                'location_id' => $locationId,
            ]);
        }

        app(StaffLocationAccess::class)->forget();
    }

    private function stamp(Model $model, string $at): void
    {
        $model->forceFill([
            'created_at' => $at,
            'updated_at' => $at,
        ])->save();
    }

    private function figure(string $html, string $name): string
    {
        $this->assertSame(1, preg_match('/data-'.$name.'="([^"]*)"/', $html, $matches));

        return $matches[1];
    }

    /**
     * @return list<array<string, string>>
     */
    private function records(string $csv): array
    {
        $rows = [];
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);

        while (($row = fgetcsv($handle)) !== false) {
            $cells = [];

            foreach ($row as $cell) {
                $cells[] = is_string($cell) ? $cell : '';
            }

            $rows[] = $cells;
        }

        fclose($handle);
        $header = array_shift($rows);
        $this->assertIsArray($header);
        $records = [];

        foreach ($rows as $row) {
            $record = [];

            foreach ($header as $index => $column) {
                $record[$column] = $row[$index] ?? '';
            }

            $records[] = $record;
        }

        return $records;
    }

    /**
     * @param  list<array<string, string>>  $records
     * @return array<string, string>
     */
    private function record(array $records, string $record, ?string $match = null): array
    {
        foreach ($records as $row) {
            if (($row['record'] ?? '') !== $record) {
                continue;
            }

            if ($match === null || ($row['service'] ?? '') === $match || ($row['date'] ?? '') === $match || ($row['channel'] ?? '') === $match) {
                return $row;
            }
        }

        $this->fail('Missing '.$record.' row.');
    }
}
