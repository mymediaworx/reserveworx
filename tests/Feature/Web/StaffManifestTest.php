<?php

namespace Tests\Feature\Web;

use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Manifest;
use App\Models\AddOn;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingAddOn;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Participant;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffManifestTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_manifest_stays_inside_the_workspace_location_and_day(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $north = $this->location($alpha['tenant'], 'North dock', 'UTC');
        $south = $this->location($alpha['tenant'], 'South dock', 'UTC');
        $betaDock = $this->location($beta['tenant'], 'Beta dock', 'UTC');
        $northBooking = $this->booking($alpha, $north, '2026-06-16 15:00:00', 'North Guest');
        $absent = $this->booking($alpha, $north, '2026-06-16 16:00:00', 'Absent Guest', BookingStatus::NoShow);
        $this->booking($alpha, $south, '2026-06-16 14:00:00', 'South Guest');
        $this->booking($alpha, $north, '2026-06-17 15:00:00', 'Later Guest');
        $this->booking($alpha, $north, '2026-06-16 15:30:00', 'Held Guest', BookingStatus::Held);
        $this->booking($alpha, $north, '2026-06-16 15:45:00', 'Cancelled Guest', BookingStatus::Cancelled);
        $this->booking($beta, $betaDock, '2026-06-16 15:00:00', 'Beta Guest');

        $this->actingAs($alpha['user']);
        $page = $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-16'))
            ->assertOk()
            ->assertSee('Dispatch manifest')
            ->assertSee('href="/staff/manifest"', false)
            ->assertSee('North Guest')
            ->assertSee('Absent Guest')
            ->assertSee('South Guest')
            ->assertSee('No show')
            ->assertDontSee('Later Guest')
            ->assertDontSee('Held Guest')
            ->assertDontSee('Cancelled Guest')
            ->assertDontSee('Beta Guest');
        $html = (string) $page->getContent();
        $this->assertSeeInOrder($html, ['North dock', 'North Guest', 'Absent Guest', 'South dock', 'South Guest']);
        $this->assertStringContainsString('booking-cards', $html);
        $this->assertStringContainsString('table-wrap responsive', $html);
        $this->assertStringContainsString('@media (max-width: 390px)', $html);
        $this->assertStringContainsString('@media print', $html);
        $this->assertStringContainsString('break-after: page', $html);
        $this->assertStringContainsString('page-break-after: always', $html);
        $this->assertStringContainsString('.manifest-location:last-child { break-after: auto; page-break-after: auto; }', $html);
        $this->assertSame(1, preg_match('/<nav class="tab-bar"[\s\S]*?<\/nav>/', $html, $nav));
        $this->assertSame(5, substr_count($nav[0], '<a '));
        $this->assertStringNotContainsString('Manifest', $nav[0]);
        $this->assertStringContainsString('data-booking="'.$northBooking->id.'"', $html);

        $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-16&location='.$south->id))
            ->assertOk()
            ->assertSee('South Guest')
            ->assertDontSee('North Guest')
            ->assertDontSee('Beta Guest');
        $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-16&location='.$betaDock->id))
            ->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('href="/staff/manifest"', false);

        $csv = $this->get($this->tenantUrl('alpha.test', '/staff/manifest.csv?date=2026-06-16'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = (string) $csv->getContent();
        $this->assertStringContainsString('North Guest', $body);
        $this->assertStringContainsString('South Guest', $body);
        $this->assertStringNotContainsString('Beta Guest', $body);
        $this->assertStringNotContainsString('Held Guest', $body);
        $this->assertStringNotContainsString('Later Guest', $body);

        $southCsv = (string) $this->get($this->tenantUrl('alpha.test', '/staff/manifest.csv?date=2026-06-16&location='.$south->id))
            ->assertOk()
            ->getContent();
        $this->assertStringContainsString('South Guest', $southCsv);
        $this->assertStringNotContainsString('North Guest', $southCsv);
        $this->get($this->tenantUrl('alpha.test', '/staff/manifest.csv?date=2026-06-16&location='.$betaDock->id))
            ->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/manifest.csv?date=not-a-day'))
            ->assertStatus(422);
    }

    public function test_rows_follow_each_locations_local_time_including_daylight_saving(): void
    {
        $alpha = $this->workspace('alpha.test');
        $east = $this->location($alpha['tenant'], 'East river', 'America/New_York');
        $island = $this->location($alpha['tenant'], 'Island dock', 'Pacific/Auckland');
        $eastAfternoon = $this->booking($alpha, $east, '2026-06-15 18:00:00', 'East Guest', endsAt: '2026-06-15 19:00:00');
        $late = $this->booking($alpha, $east, '2026-06-16 03:30:00', 'Late Guest', endsAt: '2026-06-16 04:30:00');
        $early = $this->booking($alpha, $east, '2026-06-16 04:15:00', 'Early Guest');
        $islandBooking = $this->booking($alpha, $island, '2026-06-15 18:00:00', 'Island Guest');
        $spring = $this->booking($alpha, $east, '2026-03-08 12:00:00', 'Spring Guest');
        $beforeSpring = $this->booking($alpha, $east, '2026-03-08 04:30:00', 'Before Spring');
        $firstFall = $this->booking($alpha, $east, '2026-11-01 05:30:00', 'First Fall');
        $secondFall = $this->booking($alpha, $east, '2026-11-01 06:30:00', 'Second Fall');
        $beforeFall = $this->booking($alpha, $east, '2026-11-01 03:30:00', 'Before Fall');

        $this->actingAs($alpha['user']);
        $june15 = (string) $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-15'))
            ->assertOk()
            ->assertSee('East Guest')
            ->assertSee('Late Guest')
            ->assertDontSee('Island Guest')
            ->assertDontSee('Early Guest')
            ->getContent();
        $this->assertSeeInOrder($june15, ['East Guest', 'Late Guest']);
        $this->assertStringContainsString('data-booking="'.$eastAfternoon->id.'" data-local-start="2026-06-15T14:00" data-return-due="2026-06-15T15:00"', $june15);
        $this->assertStringContainsString('data-booking="'.$late->id.'" data-local-start="2026-06-15T23:30" data-return-due="2026-06-16T00:30"', $june15);
        $this->assertStringNotContainsString('data-booking="'.$islandBooking->id.'"', $june15);

        $june16 = (string) $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-06-16'))
            ->assertOk()
            ->assertSee('Early Guest')
            ->assertSee('Island Guest')
            ->assertDontSee('East Guest')
            ->assertDontSee('Late Guest')
            ->getContent();
        $this->assertSeeInOrder($june16, ['East river', 'Early Guest', 'Island dock', 'Island Guest']);
        $this->assertStringContainsString('data-booking="'.$early->id.'" data-local-start="2026-06-16T00:15"', $june16);
        $this->assertStringContainsString('data-booking="'.$islandBooking->id.'" data-local-start="2026-06-16T06:00"', $june16);

        $csv = $this->rows((string) $this->get($this->tenantUrl('alpha.test', '/staff/manifest.csv?date=2026-06-15'))->assertOk()->getContent());
        $lateRow = $this->rowFor($csv, $late->id);
        $this->assertSame('2026-06-15 23:30', $lateRow['starts_at']);
        $this->assertSame('2026-06-16 00:30', $lateRow['return_due']);
        $this->assertSame('America/New_York', $lateRow['timezone']);
        $this->assertArrayNotHasKey($early->id, $this->byId($csv));
        $this->assertArrayNotHasKey($islandBooking->id, $this->byId($csv));

        $springPage = (string) $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-03-08'))
            ->assertOk()
            ->assertSee('Spring Guest')
            ->assertDontSee('Before Spring')
            ->getContent();
        $this->assertStringContainsString('data-booking="'.$spring->id.'" data-local-start="2026-03-08T08:00"', $springPage);
        $this->assertStringNotContainsString('data-booking="'.$beforeSpring->id.'"', $springPage);

        $fallPage = (string) $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-11-01'))
            ->assertOk()
            ->assertSee('First Fall')
            ->assertSee('Second Fall')
            ->assertDontSee('Before Fall')
            ->getContent();
        $this->assertSeeInOrder($fallPage, ['First Fall', 'Second Fall']);
        $this->assertStringContainsString('data-booking="'.$firstFall->id.'" data-local-start="2026-11-01T01:30"', $fallPage);
        $this->assertStringContainsString('data-booking="'.$secondFall->id.'" data-local-start="2026-11-01T01:30"', $fallPage);
        $this->assertStringNotContainsString('data-booking="'.$beforeFall->id.'"', $fallPage);
    }

    public function test_waiver_and_balance_columns_follow_the_check_in_rules(): void
    {
        $alpha = $this->workspace('alpha.test');
        $dock = $this->location($alpha['tenant'], 'North dock', 'UTC');
        $booking = $this->booking($alpha, $dock, '2026-07-20 15:00:00', 'Ada North', phone: '555-0100', party: 2, operational: OperationalStatus::CheckedIn, notes: 'Dock side, "paddles"');
        $older = $this->waiver($alpha['tenant'], 'Liability', 1);
        $current = $this->waiver($alpha['tenant'], 'Liability', 2);
        $ada = $this->participant($alpha['tenant'], $booking, 'Ada', false);
        $sam = $this->participant($alpha['tenant'], $booking, 'Sam', true);
        $this->sign($alpha['tenant'], $booking, $current, $ada, 'Ada', false, null);
        $this->sign($alpha['tenant'], $booking, $older, $sam, 'Sam', true, 'Pat North');
        $this->sign($alpha['tenant'], $booking, $current, $sam, 'Sam', true, null);
        $asset = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $dock->id,
            'name' => 'Harbor kayak',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $dock->id,
            'name' => 'Life jackets',
        ]);
        $this->useTenant($alpha['tenant']);
        Allocation::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
        ]);
        Allocation::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $booking->id,
            'pool_id' => $pool->id,
            'quantity' => 2,
            'starts_at' => $booking->starts_at,
            'ends_at' => $booking->ends_at,
        ]);
        $catalog = AddOn::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Cooler',
            'kind' => AddOnKind::Addon,
            'pricing' => AddOnPricing::Each,
            'amount_cents' => 500,
        ]);
        BookingAddOn::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $booking->id,
            'add_on_id' => $catalog->id,
            'name' => 'Cooler',
            'kind' => AddOnKind::Addon,
            'pricing' => AddOnPricing::Each,
            'unit_amount_cents' => 500,
            'quantity' => 2,
            'total_cents' => 1000,
        ]);
        $this->price($alpha['tenant'], $booking, 10000, 300);
        $this->payment($alpha['tenant'], $booking, 4000);
        $this->refund($alpha['tenant'], $booking, 500);

        $offline = $this->booking($alpha, $dock, '2026-07-20 18:00:00', 'Bea North', payment: PaymentStatus::OfflinePaid);
        $this->price($alpha['tenant'], $offline, 10000, 300);
        $this->payment($alpha['tenant'], $offline, 9700);
        $unpriced = $this->booking($alpha, $dock, '2026-07-20 19:00:00', 'Cam North');

        $this->actingAs($alpha['user']);
        $page = $this->get($this->tenantUrl('alpha.test', '/staff/manifest?date=2026-07-20'))
            ->assertOk()
            ->assertSee('Ada North')
            ->assertSee('555-0100')
            ->assertSee('Ada: Signed; Sam (minor): Needs Liability')
            ->assertSee('Cooler × 2')
            ->assertSee('Harbor kayak, Life jackets × 2')
            ->assertSee('$65.00')
            ->assertSee('Checked in')
            ->assertSee('Dock side, "paddles"')
            ->assertSee('$0.00')
            ->assertSee('This booking: Needs Liability')
            ->assertSee('Unassigned')
            ->assertSee('No price yet');
        $html = (string) $page->getContent();
        $this->assertStringContainsString('data-booking="'.$booking->id.'" data-local-start="2026-07-20T15:00" data-return-due="2026-07-20T16:00" data-balance="6500"', $html);
        $this->assertStringContainsString('data-booking="'.$offline->id.'" data-local-start="2026-07-20T18:00" data-return-due="2026-07-20T19:00" data-balance="0"', $html);
        $this->assertStringContainsString('data-booking="'.$unpriced->id.'" data-local-start="2026-07-20T19:00" data-return-due="2026-07-20T20:00" data-balance="none"', $html);

        $rows = $this->byId($this->rows((string) $this->get($this->tenantUrl('alpha.test', '/staff/manifest.csv?date=2026-07-20'))->assertOk()->getContent()));
        $this->assertSame('6500', $rows[$booking->id]['balance_due_cents']);
        $this->assertSame('USD', $rows[$booking->id]['currency']);
        $this->assertSame('Ada: Signed; Sam (minor): Needs Liability', $rows[$booking->id]['waivers']);
        $this->assertSame('Ada; Sam (minor)', $rows[$booking->id]['participants']);
        $this->assertSame('2', $rows[$booking->id]['party_size']);
        $this->assertSame('Cooler × 2', $rows[$booking->id]['add_ons']);
        $this->assertSame('Harbor kayak, Life jackets × 2', $rows[$booking->id]['resource']);
        $this->assertSame('555-0100', $rows[$booking->id]['customer_phone']);
        $this->assertSame('Checked in', $rows[$booking->id]['check_in']);
        $this->assertSame('Dock side, "paddles"', $rows[$booking->id]['staff_notes']);
        $this->assertSame('0', $rows[$offline->id]['balance_due_cents']);
        $this->assertSame('This booking: Needs Liability', $rows[$offline->id]['waivers']);
        $this->assertSame('', $rows[$unpriced->id]['balance_due_cents']);
    }

    public function test_viewing_requires_booking_access_and_notes_require_edit_access(): void
    {
        $staff = $this->workspace('dock.test', 'staff');
        $dock = $this->location($staff['tenant'], 'Dock', 'UTC');
        $booking = $this->booking($staff, $dock, '2026-07-20 15:00:00', 'Staff Guest', notes: 'Keep the blue one');
        $beta = $this->workspace('beta.test');
        $betaBooking = $this->booking($beta, $this->location($beta['tenant'], 'Beta dock', 'UTC'), '2026-07-20 15:00:00', 'Beta Guest');

        $this->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-07-20'))
            ->assertRedirect('/staff/login');
        $this->get($this->tenantUrl('dock.test', '/staff/manifest.csv?date=2026-07-20'))
            ->assertRedirect('/staff/login');

        $this->actingAs($staff['user'])
            ->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-07-20'))
            ->assertOk()
            ->assertSee('Staff Guest')
            ->assertSee('Keep the blue one')
            ->assertDontSee('Beta Guest');

        app(TenantManager::class)->set($staff['tenant']);
        Livewire::test(Manifest::class)
            ->set('date', '2026-07-20')
            ->call('editNote', $booking->id)
            ->set('note', 'Bring the tandem')
            ->call('saveNote')
            ->assertSee('Note saved.');

        app(TenantManager::class)->set($staff['tenant']);
        $this->assertSame('Bring the tandem', $booking->fresh()?->staff_notes);
        $audit = AuditLog::query()->where('action', 'booking.staff_note')->firstOrFail();
        $this->assertSame($booking->id, $audit->properties['booking_id'] ?? null);
        $this->assertStringNotContainsString('Bring the tandem', (string) json_encode($audit->properties));
        $this->assertStringContainsString('Bring the tandem', (string) $this->actingAs($staff['user'])->get($this->tenantUrl('dock.test', '/staff/manifest.csv?date=2026-07-20'))->getContent());

        app(TenantManager::class)->set($staff['tenant']);
        Livewire::test(Manifest::class)
            ->call('editNote', $betaBooking->id)
            ->assertNotFound();
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertNull($betaBooking->fresh()?->staff_notes);

        app(TenantManager::class)->set($staff['tenant']);
        Livewire::test(Manifest::class)
            ->set('editing', $booking->id)
            ->set('note', str_repeat('a', 1001))
            ->call('saveNote')
            ->assertHasErrors(['note']);
        app(TenantManager::class)->set($staff['tenant']);
        $this->assertSame('Bring the tandem', $booking->fresh()?->staff_notes);

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.manage')->firstOrFail()->id);
        app(TenantManager::class)->set($staff['tenant']);
        Livewire::test(Manifest::class)
            ->set('date', '2026-07-20')
            ->set('editing', $booking->id)
            ->set('note', 'Should not save')
            ->call('saveNote')
            ->assertForbidden();
        app(TenantManager::class)->set($staff['tenant']);
        $this->assertSame('Bring the tandem', $booking->fresh()?->staff_notes);

        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id);
        $this->actingAs($staff['user'])
            ->get($this->tenantUrl('dock.test', '/staff/manifest?date=2026-07-20'))
            ->assertForbidden();
        $this->get($this->tenantUrl('dock.test', '/staff/manifest.csv?date=2026-07-20'))
            ->assertForbidden();
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function location(Tenant $tenant, string $name, string $timezone): Location
    {
        $this->useTenant($tenant);

        return Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => $timezone,
        ]);
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function booking(
        array $workspace,
        Location $location,
        string $start,
        string $customerName,
        BookingStatus $status = BookingStatus::Confirmed,
        ?string $endsAt = null,
        ?string $phone = null,
        int $party = 1,
        OperationalStatus $operational = OperationalStatus::NotArrived,
        ?string $notes = null,
        PaymentStatus $payment = PaymentStatus::Unpaid,
    ): Booking {
        $this->useTenant($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Paddle',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '.', $customerName)).'@example.com',
            'phone' => $phone,
        ]);
        $starts = CarbonImmutable::parse($start, 'UTC');
        $ends = CarbonImmutable::parse($endsAt ?? $starts->addHour()->utc()->format('Y-m-d H:i:s'), 'UTC');

        return Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'payment_status' => $payment,
            'operational_status' => $operational,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => $party,
            'staff_notes' => $notes,
            'payload_hash' => hash('sha256', $customerName.$start),
        ]);
    }

    private function waiver(Tenant $tenant, string $name, int $version): WaiverVersion
    {
        $this->useTenant($tenant);

        return WaiverVersion::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'version' => $version,
            'body' => $name.' '.$version,
            'content_hash' => hash('sha256', $name.' '.$version),
        ]);
    }

    private function participant(Tenant $tenant, Booking $booking, string $name, bool $minor): Participant
    {
        $this->useTenant($tenant);

        return Participant::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'name' => $name,
            'is_minor' => $minor,
            'payload_hash' => hash('sha256', $booking->id.$name),
        ]);
    }

    private function sign(
        Tenant $tenant,
        Booking $booking,
        WaiverVersion $version,
        Participant $participant,
        string $name,
        bool $minor,
        ?string $guardian,
    ): void {
        $this->useTenant($tenant);
        WaiverSignature::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'participant_id' => $participant->id,
            'waiver_version_id' => $version->id,
            'participant_name' => $name,
            'is_minor' => $minor,
            'guardian_name' => $guardian,
            'content_hash' => $version->content_hash,
            'signed_at' => '2026-07-20 12:00:00',
        ]);
    }

    private function price(Tenant $tenant, Booking $booking, int $total, int $feeLine): void
    {
        $this->useTenant($tenant);
        PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'v1',
            'payload' => [
                'customer_total_cents' => $total,
                'customer_fee_line_cents' => $feeLine,
            ],
        ]);
    }

    private function payment(Tenant $tenant, Booking $booking, int $cents): void
    {
        $this->useTenant($tenant);
        Payment::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'method' => 'card',
            'kind' => 'balance',
            'status' => 'paid',
            'amount_cents' => $cents,
        ]);
    }

    private function refund(Tenant $tenant, Booking $booking, int $cents): void
    {
        $this->useTenant($tenant);
        Refund::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'reason' => 'decrease',
            'rules_version' => 'v1',
            'customer_refund_cents' => $cents,
            'customer_fee_refund_cents' => 0,
            'application_fee_refund_cents' => 0,
            'refund_subtotal_cents' => $cents,
            'refund_tax_cents' => 0,
        ]);
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    /**
     * @return list<array<int, string|null>>
     */
    private function rows(string $csv): array
    {
        $handle = fopen('php://temp', 'w+');
        $this->assertNotFalse($handle);
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param  list<array<int, string|null>>  $rows
     * @return array<string, array<string, string>>
     */
    private function byId(array $rows): array
    {
        $header = array_shift($rows);
        $this->assertIsArray($header);
        $indexed = [];

        foreach ($rows as $row) {
            $assoc = [];

            foreach ($header as $index => $column) {
                if (! is_string($column)) {
                    continue;
                }

                $assoc[$column] = (string) ($row[$index] ?? '');
            }

            $indexed[$assoc['booking_id']] = $assoc;
        }

        return $indexed;
    }

    /**
     * @param  list<array<int, string|null>>  $rows
     * @return array<string, string>
     */
    private function rowFor(array $rows, string $id): array
    {
        $indexed = $this->byId($rows);
        $this->assertArrayHasKey($id, $indexed);

        return $indexed[$id];
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertSeeInOrder(string $html, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = strpos($html, $needle, $offset);
            $this->assertNotFalse($position, 'Missing '.$needle.' after the previous marker.');
            $offset = $position + strlen($needle);
        }
    }
}
