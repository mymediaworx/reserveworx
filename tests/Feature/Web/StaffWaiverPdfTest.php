<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffWaiverPdfTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_download_the_stored_waiver_from_the_booking_page(): void
    {
        $workspace = $this->workspace('alpha.test', 'staff');
        $shop = $this->rentalShop($workspace['tenant']);
        $booking = $this->booking($workspace['tenant'], $shop['location'], $shop['service'], 'Sam River', 'sam@example.com');
        $empty = $this->booking($workspace['tenant'], $shop['location'], $shop['service'], 'Pat None', 'pat@example.com');
        $signed = $this->signature($workspace['tenant'], $booking, 'Sam River', true, 'Ada River');
        $this->actingAs($workspace['user']);

        $beforeAudit = AuditLog::query()->count();
        $beforePayments = Payment::query()->count();
        $beforeRefunds = Refund::query()->count();
        $beforeFees = PlatformFee::query()->count();

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$booking->id))
            ->assertOk()
            ->assertSee('Signed waivers')
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('Sam River')
            ->assertSee('under 18, Ada River')
            ->assertSee('Rental waiver')
            ->assertSee('version 1')
            ->assertSee('Tue, Oct 27, 11:04 AM EDT')
            ->assertSee('Download PDF')
            ->assertSee('/staff/bookings/'.$booking->id.'/waivers/'.$signed->id, false)
            ->assertSee('It is not an electronic-signature certificate.')
            ->assertDontSee('203.0.113.20');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$empty->id))
            ->assertOk()
            ->assertSee('No one has signed a waiver for this booking.')
            ->assertDontSee('Download PDF');

        $pdf = $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$booking->id.'/waivers/'.$signed->id));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('waiver-'.$signed->id.'.pdf', (string) $pdf->headers->get('content-disposition'));
        $body = (string) $pdf->getContent();
        $this->assertStringStartsWith('%PDF-1.4', $body);
        $this->assertStringContainsString('Sam River', $body);
        $this->assertStringContainsString('Ada River', $body);
        $this->assertStringContainsString($signed->content_hash, $body);
        $this->assertStringContainsString('not an attorney-approved', $body);
        $this->assertStringContainsString('I accept the risks of this rental.', $body);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame($signed->content_hash, WaiverSignature::query()->findOrFail($signed->id)->content_hash);
        $this->assertSame($beforeAudit, AuditLog::query()->count());
        $this->assertSame($beforePayments, Payment::query()->count());
        $this->assertSame($beforeRefunds, Refund::query()->count());
        $this->assertSame($beforeFees, PlatformFee::query()->count());

        $this->app['auth']->forgetGuards();
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$booking->id.'/waivers/'.$signed->id))
            ->assertRedirect('/staff/login');
    }

    public function test_a_signature_from_another_booking_location_or_workspace_is_not_found(): void
    {
        $workspace = $this->workspace('alpha.test', 'staff');
        $north = $this->rentalShop($workspace['tenant']);
        $south = $this->dock($workspace['tenant'], 'South dock');
        $here = $this->booking($workspace['tenant'], $north['location'], $north['service'], 'North Guest', 'north@example.com');
        $other = $this->booking($workspace['tenant'], $north['location'], $north['service'], 'Other Guest', 'other@example.com');
        $southBooking = $this->booking($workspace['tenant'], $south['location'], $south['service'], 'South Guest', 'south@example.com');
        $hereSignature = $this->signature($workspace['tenant'], $here, 'North Guest', false, null);
        $otherSignature = $this->signature($workspace['tenant'], $other, 'Other Guest', false, null);
        $southSignature = $this->signature($workspace['tenant'], $southBooking, 'South Guest', false, null);

        $beta = $this->workspace('beta.test', 'staff');
        $betaShop = $this->rentalShop($beta['tenant'], 4, 'America/New_York');
        $betaBooking = $this->booking($beta['tenant'], $betaShop['location'], $betaShop['service'], 'Beta Guest', 'beta@example.com');
        $betaSignature = $this->signature($beta['tenant'], $betaBooking, 'Beta Guest', false, null);

        $this->limit($workspace['membership'], $north['location']->id);
        $this->actingAs($workspace['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$here->id.'/waivers/'.$otherSignature->id))
            ->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$southBooking->id.'/waivers/'.$southSignature->id))
            ->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$betaBooking->id.'/waivers/'.$betaSignature->id))
            ->assertNotFound();
        $this->get($this->tenantUrl('beta.test', '/staff/bookings/'.$here->id.'/waivers/'.$hereSignature->id))
            ->assertNotFound();

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/'.$here->id.'/waivers/'.$hereSignature->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    private function signature(Tenant $tenant, Booking $booking, string $name, bool $minor, ?string $guardian): WaiverSignature
    {
        app(TenantManager::class)->set($tenant);
        $body = 'I accept the risks of this rental.';
        $version = WaiverVersion::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'name' => 'Rental waiver', 'version' => 1],
            ['body' => $body, 'content_hash' => hash('sha256', $body)],
        );

        return WaiverSignature::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'waiver_version_id' => $version->id,
            'participant_name' => $name,
            'is_minor' => $minor,
            'guardian_name' => $guardian,
            'ip_address' => '203.0.113.20',
            'user_agent' => 'ReserveWorxTest',
            'content_hash' => $version->content_hash,
            'signed_at' => CarbonImmutable::parse('2026-10-27 15:04:00', 'UTC'),
        ]);
    }

    private function limit(Membership $membership, string $locationId): void
    {
        $membership->locations_restricted = true;
        $membership->save();
        MembershipLocation::query()->create([
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'location_id' => $locationId,
        ]);
        app(StaffLocationAccess::class)->forget();
    }

    /**
     * @return array{location: Location, service: Service}
     */
    private function dock(Tenant $tenant, string $name): array
    {
        app(TenantManager::class)->set($tenant);
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => 'America/New_York',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name.' rental',
            'kind' => 'rental',
        ]);

        return ['location' => $location, 'service' => $service];
    }

    private function booking(Tenant $tenant, Location $location, Service $service, string $name, string $email): Booking
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-11-03 15:00:00',
            'ends_at' => '2026-11-03 16:00:00',
            'participants' => 2,
            'payload_hash' => hash('sha256', $email),
        ]);
    }
}
