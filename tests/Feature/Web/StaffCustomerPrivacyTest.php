<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\CustomerDataExportStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Customers;
use App\Livewire\Staff\Menu;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerDataDeletion;
use App\Models\CustomerDataExport;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaiverSignature;
use App\Models\WaiverVersion;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffCustomerPrivacyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_downloads_a_record_and_removes_personal_details(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $workspace = $this->signedIn();
        $graph = $this->graph($workspace['tenant']->id);
        $customer = $graph['customer'];

        $page = Livewire::test(Customers::class)
            ->assertSee('Customer records')
            ->assertSee('Ada Kayak')
            ->assertSee('overflow-wrap: anywhere', false)
            ->call('exportCustomer', $customer->id)
            ->assertSee('The download is ready. The link lasts 30 minutes.')
            ->assertSee($customer->id, false)
            ->assertSee('signature=', false);

        $html = $page->html();
        $this->assertMatchesRegularExpression(
            '/<a href="[^"]*'.preg_quote($customer->id, '/').'[^"]*signature=[^"]*">Download<\/a>/',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<a href="[^"]*signature=[^"]*" wire:navigate>/',
            $html,
        );

        $files = Storage::disk('local')->allFiles();
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('customer-exports/'.$workspace['tenant']->id.'/', $files[0]);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $page->call('eraseCustomer', $customer->id)
            ->assertSee('Personal details were removed. Bookings, payments, and signed waivers stay.')
            ->assertSee('Removed customer')
            ->assertSee('Personal details removed')
            ->assertDontSee('wire:click="eraseCustomer', false);

        $page->call('eraseCustomer', $customer->id)
            ->assertSee('This customer was already removed.');

        $erased = $customer->fresh();
        $this->assertInstanceOf(Customer::class, $erased);
        $this->assertSame('Removed customer', $erased->name);
        $this->assertNull($erased->email);
        $this->assertNull($erased->phone);

        $payment = $graph['payment']->fresh();
        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertSame(2500, $payment->amount_cents);
        $this->assertSame(75, $payment->fee_cents);

        $booking = $graph['booking']->fresh();
        $this->assertInstanceOf(Booking::class, $booking);
        $this->assertSame(BookingStatus::Confirmed, $booking->status);

        $signature = $graph['signature']->fresh();
        $this->assertInstanceOf(WaiverSignature::class, $signature);
        $this->assertSame($graph['hash'], $signature->content_hash);
        $this->assertSame('Removed', $signature->participant_name);
        $this->assertSame(1, WaiverSignature::query()->count());
        $this->assertSame(1, CustomerDataDeletion::query()->count());
    }

    public function test_removal_is_refused_until_a_file_exists_unless_the_box_is_checked(): void
    {
        Storage::fake('local');
        $workspace = $this->signedIn('manager');
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
        ]);

        Livewire::test(Customers::class)
            ->call('eraseCustomer', $customer->id)
            ->assertSee('Deletion is refused until a customer data export exists.')
            ->assertSee('Ada Kayak');

        $this->assertSame('Ada Kayak', $customer->fresh()?->name);
        $this->assertTrue(
            AuditLog::query()->where('action', 'customer.data_deletion_refused')->where('subject_id', $customer->id)->exists(),
        );
        $this->assertSame(0, CustomerDataDeletion::query()->count());

        Livewire::test(Customers::class)
            ->set('confirmWithoutFile', true)
            ->call('eraseCustomer', $customer->id)
            ->assertSee('Removed customer')
            ->assertSee('Personal details were removed. Bookings, payments, and signed waivers stay.');

        $this->assertSame('Removed customer', $customer->fresh()?->name);
        $this->assertNull($customer->fresh()?->email);
    }

    public function test_search_matches_name_email_and_phone_and_stops_at_fifty(): void
    {
        $workspace = $this->signedIn();
        $tenantId = $workspace['tenant']->id;
        Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
        ]);
        Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Bea Paddle',
            'email' => 'bea@example.com',
            'phone' => '312-555-0199',
        ]);
        Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => '100% Off',
            'email' => 'percent@example.com',
        ]);
        Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => '100X Off',
            'email' => 'plain@example.com',
        ]);

        Livewire::test(Customers::class)
            ->set('q', '555-0100')
            ->assertSee('Ada Kayak')
            ->assertDontSee('Bea Paddle')
            ->set('q', 'bea@example.com')
            ->assertSee('Bea Paddle')
            ->assertDontSee('Ada Kayak')
            ->set('q', '100%')
            ->assertSee('100% Off')
            ->assertDontSee('100X Off');

        $this->get($this->tenantUrl('alpha.test', '/staff/customers?q='.urlencode('Ada')))
            ->assertOk()
            ->assertSee('Ada Kayak')
            ->assertDontSee('Bea Paddle');

        for ($number = 1; $number <= 51; $number++) {
            Customer::query()->create([
                'tenant_id' => $tenantId,
                'name' => sprintf('Guest %02d', $number),
                'email' => sprintf('guest%02d@example.com', $number),
            ]);
        }

        Livewire::test(Customers::class)
            ->set('q', 'Guest')
            ->assertSee('Guest 01')
            ->assertSee('Guest 50')
            ->assertDontSee('Guest 51');
    }

    public function test_a_queued_export_says_it_is_preparing_and_a_failed_one_says_so(): void
    {
        Storage::fake('local');
        Queue::fake();
        $workspace = $this->signedIn();
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
        ]);

        Livewire::test(Customers::class)
            ->call('exportCustomer', $customer->id)
            ->assertSee('The download is being prepared.')
            ->assertSee('Preparing the download')
            ->assertDontSee('signature=', false);

        $this->assertSame([], Storage::disk('local')->allFiles());

        CustomerDataExport::query()->where('customer_id', $customer->id)->update([
            'status' => CustomerDataExportStatus::Failed,
            'failed_at' => now(),
        ]);

        Livewire::test(Customers::class)
            ->assertSee('Could not prepare the download.');
    }

    public function test_staff_and_a_location_limited_member_are_refused(): void
    {
        $this->signedIn('staff');
        $this->get($this->tenantUrl('alpha.test', '/staff/customers'))->assertForbidden();
        Livewire::test(Customers::class)->assertForbidden();
    }

    public function test_a_location_limited_owner_is_refused_and_the_link_is_hidden(): void
    {
        $owner = $this->signedIn();
        $home = Location::factory()->create([
            'tenant_id' => $owner['tenant']->id,
            'name' => 'Home dock',
        ]);
        $owner['membership']->locations_restricted = true;
        $owner['membership']->save();
        MembershipLocation::query()->create([
            'tenant_id' => $owner['tenant']->id,
            'membership_id' => $owner['membership']->id,
            'location_id' => $home->id,
        ]);
        app(StaffLocationAccess::class)->forget();

        $this->get($this->tenantUrl('alpha.test', '/staff/customers'))->assertForbidden();
        Livewire::test(Customers::class)->assertForbidden();

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertDontSee('/staff/customers', false);
    }

    public function test_another_workspace_customer_is_hidden_and_cannot_be_changed(): void
    {
        Storage::fake('local');
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $foreign = Customer::query()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta Person',
            'email' => 'beta@example.com',
        ]);
        Customer::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
        ]);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        Livewire::test(Customers::class)
            ->assertSee('Ada Kayak')
            ->assertDontSee('Beta Person')
            ->call('exportCustomer', $foreign->id)
            ->assertNotFound();

        Livewire::test(Customers::class)
            ->set('confirmWithoutFile', true)
            ->call('eraseCustomer', $foreign->id)
            ->assertNotFound();

        $untouched = Customer::withoutGlobalScope(TenantScope::class)->findOrFail($foreign->id);
        $this->assertSame('Beta Person', $untouched->name);
        $this->assertSame('beta@example.com', $untouched->email);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_guest_is_sent_to_the_staff_login(): void
    {
        $this->workspace('alpha.test');

        $this->get($this->tenantUrl('alpha.test', '/staff/customers'))
            ->assertRedirect('/staff/login');
    }

    public function test_the_menu_shows_customer_records_only_with_privacy_access(): void
    {
        $workspace = $this->signedIn();

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('Customer records')
            ->assertSee('/staff/customers', false);

        Livewire::test(Menu::class)->assertSee('Customer records');

        $staff = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $workspace['tenant']->id,
            'user_id' => $staff->id,
        ]);
        $this->actingAs($staff);

        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertDontSee('Customer records')
            ->assertDontSee('/staff/customers', false);
    }

    public function test_an_empty_workspace_says_there_are_no_customers_yet(): void
    {
        $this->signedIn();

        $this->get($this->tenantUrl('alpha.test', '/staff/customers'))
            ->assertOk()
            ->assertSee('No customers yet.')
            ->assertSee('Remove personal details even when a download file is not ready.');
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(string $role = 'owner'): array
    {
        $workspace = $this->workspace('alpha.test', $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    /**
     * @return array{customer: Customer, booking: Booking, payment: Payment, signature: WaiverSignature, hash: string}
     */
    private function graph(string $tenantId): array
    {
        $customer = Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
        ]);
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 1,
            'payload_hash' => hash('sha256', $customer->id),
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 2500,
            'fee_cents' => 75,
        ]);
        $hash = hash('sha256', 'liability-waiver');
        $version = WaiverVersion::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Liability',
            'version' => 1,
            'body' => 'liability-waiver',
            'content_hash' => $hash,
        ]);
        $signature = WaiverSignature::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $booking->id,
            'waiver_version_id' => $version->id,
            'participant_name' => 'Ada Kayak',
            'content_hash' => $hash,
            'signed_at' => '2026-06-01 12:00:00',
        ]);

        return [
            'customer' => $customer,
            'booking' => $booking,
            'payment' => $payment,
            'signature' => $signature,
            'hash' => $hash,
        ];
    }
}
