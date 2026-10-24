<?php

namespace Tests\Feature\Reports;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Permission;
use App\Models\PricingSnapshot;
use App\Models\ReportSummary;
use App\Models\Role;
use App\Models\Service;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ReportExportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_csv_downloads_match_the_json_rows_and_keep_blank_rates_blank(): void
    {
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('other.test');
        $open = $this->location($workspace['tenant']->id, 'UTC', 'Open dock');
        $closed = $this->location($workspace['tenant']->id, 'America/Chicago', 'Closed dock');
        $foreign = $this->location($other['tenant']->id, 'UTC', 'Other dock');
        $kayak = $this->service($workspace['tenant']->id, $open->id, 'Kayak, "tandem"');
        $ada = $this->customer($workspace['tenant']->id, 'Ada');
        $first = $this->booking($workspace['tenant']->id, $open, $kayak, BookingStatus::Confirmed, '2026-06-01 15:00:00', '2026-06-01 16:00:00', $ada->id);
        $again = $this->booking($workspace['tenant']->id, $open, $kayak, BookingStatus::Confirmed, '2026-06-16 15:00:00', '2026-06-16 16:00:00', $ada->id);
        $held = $this->booking($workspace['tenant']->id, $open, $kayak, BookingStatus::Held, '2026-06-16 18:00:00', '2026-06-16 19:00:00', $ada->id);
        $this->snapshot($workspace['tenant']->id, $first, 4_000, 'direct');
        $this->snapshot($workspace['tenant']->id, $again, 6_000, 'direct');
        $this->snapshot($workspace['tenant']->id, $held, 9_999, 'direct');
        $this->summary($workspace['tenant']->id, $open, '2026-06-16', 480, 0, 0, 1_000);
        $this->summary($workspace['tenant']->id, $closed, '2026-06-16', 0, null, null, 8_000);
        $this->summary($other['tenant']->id, $foreign, '2026-06-16', 480, 1000, 1000, 50_000);

        $this->actingAsWorkspace($workspace);
        $query = 'from=2026-06-16&to=2026-06-16';

        $summaries = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/summaries?'.$query))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($summaries);
        $summaryCsv = $this->get($this->tenantUrl('dock.test', '/api/v1/reports/summaries/export?'.$query))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('content-disposition', 'attachment; filename="summaries.csv"');
        $summaryRows = $this->table((string) $summaryCsv->getContent());
        $this->assertSame([
            'location_id',
            'date',
            'timezone',
            'available_resource_minutes',
            'allocated_resource_minutes',
            'utilization_bps',
            'bookings_created',
            'bookings_converted',
            'conversion_bps',
            'average_order_cents',
            'gross_cents',
            'revenue_per_resource_hour_cents',
            'updated_at',
        ], $summaryRows[0]);
        $this->assertCount(count($summaries) + 1, $summaryRows);
        foreach ($summaries as $index => $row) {
            $this->assertIsArray($row);
            $this->assertSame($this->cells([
                $row['location_id'],
                $row['date'],
                $row['timezone'],
                $row['available_resource_minutes'],
                $row['allocated_resource_minutes'],
                $row['utilization_bps'],
                $row['bookings_created'],
                $row['bookings_converted'],
                $row['conversion_bps'],
                $row['average_order_cents'],
                $row['gross_cents'],
                $row['revenue_per_resource_hour_cents'],
                $row['updated_at'],
            ]), $summaryRows[$index + 1]);
        }
        $closedRow = collect($summaryRows)->first(fn (array $row): bool => ($row[0] ?? null) === $closed->id);
        $this->assertIsArray($closedRow);
        $this->assertSame('', $closedRow[5]);
        $this->assertSame('', $closedRow[8]);
        $this->assertSame('', $closedRow[9]);
        $this->assertSame('', $closedRow[11]);
        $utilization = collect($summaryRows)->skip(1)->map(fn (array $row): string => $row[5])->all();
        $this->assertContains('0', $utilization);
        $this->assertContains('', $utilization);
        $this->assertStringNotContainsString($foreign->id, (string) $summaryCsv->getContent());
        $this->assertSame(8000, ReportSummary::withoutGlobalScope(TenantScope::class)->where('location_id', $closed->id)->firstOrFail()->gross_cents);
        $this->assertSame(50000, ReportSummary::withoutGlobalScope(TenantScope::class)->where('location_id', $foreign->id)->firstOrFail()->gross_cents);

        $revenue = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/revenue?'.$query))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($revenue);
        $revenueCsv = $this->get($this->tenantUrl('dock.test', '/api/v1/reports/revenue/export?'.$query))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="revenue.csv"');
        $revenueRows = $this->table((string) $revenueCsv->getContent());
        $this->assertSame(['service_id', 'service', 'location_id', 'date', 'bookings', 'gross_cents'], $revenueRows[0]);
        $this->assertCount(count($revenue) + 1, $revenueRows);
        foreach ($revenue as $index => $row) {
            $this->assertIsArray($row);
            $this->assertSame($this->cells([
                $row['service_id'],
                $row['service'],
                $row['location_id'],
                $row['date'],
                $row['bookings'],
                $row['gross_cents'],
            ]), $revenueRows[$index + 1]);
        }
        $this->assertSame('Kayak, "tandem"', $revenueRows[1][1]);
        $this->assertSame('6000', $revenueRows[1][5]);
        $this->assertStringNotContainsString('9999', (string) $revenueCsv->getContent());

        $customers = $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/customers?'.$query))
            ->assertOk()
            ->json('data');
        $this->assertIsArray($customers);
        $customerCsv = $this->get($this->tenantUrl('dock.test', '/api/v1/reports/customers/export?'.$query))
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename="customers.csv"');
        $customerRows = $this->table((string) $customerCsv->getContent());
        $this->assertSame([
            'record',
            'location_id',
            'date',
            'customers',
            'new_customers',
            'repeat_customers',
            'bookings',
            'new_bookings',
            'repeat_bookings',
            'anonymous_bookings',
            'channel',
        ], $customerRows[0]);
        $customerLines = is_array($customers['customers'] ?? null) ? $customers['customers'] : [];
        $attributionLines = is_array($customers['attribution'] ?? null) ? $customers['attribution'] : [];
        $this->assertCount(count($customerLines) + count($attributionLines) + 1, $customerRows);
        foreach ($customerLines as $index => $row) {
            $this->assertIsArray($row);
            $this->assertSame($this->cells([
                'customer',
                $row['location_id'],
                $row['date'],
                $row['customers'],
                $row['new_customers'],
                $row['repeat_customers'],
                $row['bookings'],
                $row['new_bookings'],
                $row['repeat_bookings'],
                $row['anonymous_bookings'],
                null,
            ]), $customerRows[$index + 1]);
        }
        foreach ($attributionLines as $index => $row) {
            $this->assertIsArray($row);
            $this->assertSame($this->cells([
                'attribution',
                $row['location_id'],
                $row['date'],
                null,
                null,
                null,
                $row['bookings'],
                null,
                null,
                null,
                $row['channel'],
            ]), $customerRows[count($customerLines) + $index + 1]);
        }
        $this->assertSame('1', $customerRows[1][5]);
        $this->assertSame('', $customerRows[1][10]);
        $this->assertSame('direct', $customerRows[2][10]);
        $this->assertSame('', $customerRows[2][3]);

        $empty = $this->get($this->tenantUrl('dock.test', '/api/v1/reports/summaries/export?from=2025-01-01&to=2026-01-01'))
            ->assertOk();
        $this->assertCount(1, $this->table((string) $empty->getContent()));
    }

    public function test_a_member_without_booking_access_and_a_bad_window_are_refused(): void
    {
        $staff = $this->workspace('dock.test', 'staff');
        $owner = $this->workspace('harbor.test');
        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $permission = Permission::query()->where('slug', 'bookings.view')->firstOrFail();
        $role->permissions()->detach($permission->id);

        $this->actingAsWorkspace($staff);
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/summaries/export?from=2026-06-16&to=2026-06-16'))
            ->assertForbidden();
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/revenue/export?from=2026-06-16&to=2026-06-16'))
            ->assertForbidden();
        $this->getJson($this->tenantUrl('dock.test', '/api/v1/reports/customers/export?from=2026-06-16&to=2026-06-16'))
            ->assertForbidden();

        $this->actingAsWorkspace($owner);
        foreach (['summaries', 'revenue', 'customers'] as $report) {
            $this->getJson($this->tenantUrl('harbor.test', '/api/v1/reports/'.$report.'/export'))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['from', 'to']);
            $this->getJson($this->tenantUrl('harbor.test', '/api/v1/reports/'.$report.'/export?from=2026-06-17&to=2026-06-16'))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['to']);
            $this->getJson($this->tenantUrl('harbor.test', '/api/v1/reports/'.$report.'/export?from=2025-01-01&to=2026-01-02'))
                ->assertStatus(422)
                ->assertJsonValidationErrors(['to']);
        }
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

    private function customer(string $tenantId, string $name): Customer
    {
        return Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => strtolower($name).'@example.com',
        ]);
    }

    private function booking(
        string $tenantId,
        Location $location,
        Service $service,
        BookingStatus $status,
        string $starts,
        string $ends,
        string $customerId,
    ): Booking {
        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customerId,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 1,
            'payload_hash' => str_repeat('a', 64),
        ]);
    }

    private function snapshot(string $tenantId, Booking $booking, int $servicesCents, string $channel): void
    {
        PricingSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/11',
            'payload' => [
                'channel' => $channel,
                'services_cents' => $servicesCents,
                'tax_cents' => 100,
                'fee_cents' => 50,
            ],
        ]);
    }

    private function summary(
        string $tenantId,
        Location $location,
        string $date,
        int $available,
        ?int $utilization,
        ?int $revenuePerHour,
        int $gross,
    ): void {
        ReportSummary::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'summary_date' => $date,
            'available_resource_minutes' => $available,
            'allocated_resource_minutes' => 0,
            'utilization_bps' => $utilization,
            'bookings_created' => 1,
            'bookings_converted' => 1,
            'conversion_bps' => $utilization,
            'average_order_cents' => $revenuePerHour,
            'gross_cents' => $gross,
            'revenue_per_resource_hour_cents' => $revenuePerHour,
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
