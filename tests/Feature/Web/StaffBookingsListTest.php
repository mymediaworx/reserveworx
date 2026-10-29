<?php

namespace Tests\Feature\Web;

use App\Domain\Payments\StripeGateway;
use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Staff\Bookings;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingListView;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlatformFee;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\Role;
use App\Models\Service;
use App\Models\StripeAccount;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaiverVersion;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffBookingsListTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_list_filters_by_status_date_location_and_item(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant'], 4, 'America/New_York');
        $south = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'South dock',
            'timezone' => 'UTC',
        ]);
        $tour = $this->service($alpha['tenant'], $south, 'Sunset tour');
        $soon = CarbonImmutable::now('UTC')->addDays(2)->setTime(15, 0);
        $ago = CarbonImmutable::now('UTC')->subDays(3)->setTime(15, 0);
        $this->row($alpha['tenant'], $shop['location'], $shop['service'], 'Ada Lovelace', $soon, BookingStatus::Confirmed);
        $this->row($alpha['tenant'], $south, $tour, 'Grace Hopper', $soon, BookingStatus::Held);
        $this->row($alpha['tenant'], $shop['location'], $shop['service'], 'Past Paddle', $ago, BookingStatus::Confirmed);
        $beta = $this->workspace('beta.test');
        $betaShop = $this->rentalShop($beta['tenant'], 4, 'UTC');
        $this->row($beta['tenant'], $betaShop['location'], $betaShop['service'], 'Beta Customer', $soon, BookingStatus::Confirmed);

        $this->actingAs($alpha['user']);
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings'))
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('Grace Hopper')
            ->assertDontSee('Past Paddle')
            ->assertDontSee('Beta Customer')
            ->assertSee('>Item<', false)
            ->assertSee('Export CSV');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=past'))
            ->assertOk()
            ->assertSee('Past Paddle')
            ->assertDontSee('Ada Lovelace');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=all&status=held'))
            ->assertOk()
            ->assertSee('Grace Hopper')
            ->assertDontSee('Ada Lovelace');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=all&location='.$south->id))
            ->assertOk()
            ->assertSee('Grace Hopper')
            ->assertDontSee('Ada Lovelace');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=all&item='.$shop['service']->id))
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('Past Paddle')
            ->assertDontSee('Grace Hopper');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=all&location='.$betaShop['location']->id))
            ->assertOk()
            ->assertDontSee('Beta Customer')
            ->assertDontSee('Ada Lovelace');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=all&from=2026-06-20&to=2026-06-01'))
            ->assertOk()
            ->assertSee('The from date must be on or before the to date.')
            ->assertDontSee('Ada Lovelace');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=all&from=not-a-date'))
            ->assertOk()
            ->assertSee('Use dates as YYYY-MM-DD.');
    }

    public function test_the_date_range_uses_each_locations_timezone(): void
    {
        $alpha = $this->workspace('alpha.test');
        $ny = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'North dock',
            'timezone' => 'America/New_York',
        ]);
        $utc = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'UTC dock',
            'timezone' => 'UTC',
        ]);
        $nyService = $this->service($alpha['tenant'], $ny, 'Kayak rental');
        $utcService = $this->service($alpha['tenant'], $utc, 'Dawn rental');
        $instant = CarbonImmutable::parse('2026-06-15 03:30:00', 'UTC');
        $this->row($alpha['tenant'], $ny, $nyService, 'Night Paddle', $instant);
        $this->row($alpha['tenant'], $utc, $utcService, 'Dawn Paddle', $instant);

        $this->actingAs($alpha['user']);
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=all&from=2026-06-15&to=2026-06-15'))
            ->assertOk()
            ->assertSee('Dawn Paddle')
            ->assertDontSee('Night Paddle');

        $this->get($this->tenantUrl('alpha.test', '/staff/bookings?when=all&from=2026-06-14&to=2026-06-14'))
            ->assertOk()
            ->assertSee('Night Paddle')
            ->assertDontSee('Dawn Paddle');
    }

    public function test_a_saved_view_belongs_to_one_person_and_one_workspace(): void
    {
        $alpha = $this->workspace('alpha.test');
        $otherUser = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $alpha['tenant']->id,
            'user_id' => $otherUser->id,
        ]);
        $shop = $this->rentalShop($alpha['tenant'], 4, 'UTC');
        $beta = $this->workspace('beta.test');
        Membership::factory()->role('owner')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $alpha['user']->id,
        ]);
        $this->signIn($alpha);

        Livewire::test(Bookings::class)
            ->set('status', 'confirmed')
            ->set('when', 'all')
            ->set('from', '2026-06-01')
            ->set('to', '2026-06-30')
            ->set('location', $shop['location']->id)
            ->set('item', $shop['service']->id)
            ->set('search', 'Ada')
            ->set('viewName', 'Morning dock')
            ->call('saveView')
            ->assertHasNoErrors()
            ->assertSee('Saved "Morning dock".');

        $view = BookingListView::query()->firstOrFail();
        $this->assertSame($alpha['user']->id, $view->user_id);
        $this->assertSame($alpha['tenant']->id, $view->tenant_id);
        $this->assertSame('confirmed', $view->filters['status'] ?? null);
        $this->assertSame($shop['service']->id, $view->filters['item'] ?? null);
        $this->assertSame('Ada', $view->filters['search'] ?? null);
        $this->assertTrue(AuditLog::query()->where('action', 'booking_list_view.saved')->exists());

        Livewire::test(Bookings::class)
            ->set('savedView', $view->id)
            ->assertSet('status', 'confirmed')
            ->assertSet('from', '2026-06-01')
            ->assertSet('search', 'Ada')
            ->assertSet('item', $shop['service']->id)
            ->call('deleteView')
            ->assertSee('Deleted "Morning dock".');
        $this->assertNull(BookingListView::query()->find($view->id));

        Livewire::test(Bookings::class)
            ->set('status', 'held')
            ->set('viewName', 'Morning dock')
            ->call('saveView')
            ->set('status', 'confirmed')
            ->set('viewName', 'Morning dock')
            ->call('saveView');
        $this->assertSame(1, BookingListView::query()->count());
        $this->assertSame('confirmed', BookingListView::query()->firstOrFail()->filters['status'] ?? null);

        $kept = BookingListView::query()->firstOrFail();
        $this->actingAs($otherUser);
        app(TenantManager::class)->set($alpha['tenant']);
        Livewire::test(Bookings::class)
            ->assertDontSee('Morning dock')
            ->set('savedView', $kept->id)
            ->assertSet('savedView', '')
            ->assertSet('search', '')
            ->assertSee('That view is not available.');
        $this->assertNotNull(BookingListView::query()->find($kept->id));

        $this->actingAs($alpha['user']);
        app(TenantManager::class)->set($beta['tenant']);
        Livewire::test(Bookings::class)
            ->assertDontSee('Morning dock')
            ->set('savedView', $kept->id)
            ->assertSet('savedView', '');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertNotNull(BookingListView::query()->find($kept->id));
    }

    public function test_bulk_check_in_skips_a_hold_and_a_missing_waiver(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant'], 4, 'UTC');
        $confirmed = $this->held($alpha, $shop['service']->id, 'Ada Lovelace');
        $blocked = $this->held($alpha, $shop['service']->id, 'Grace Hopper');
        $held = $this->held($alpha, $shop['service']->id, 'Cara Past');
        $this->signIn($alpha);
        app(Allocator::class)->confirm($confirmed);
        app(Allocator::class)->confirm($blocked);
        WaiverVersion::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Rental waiver',
            'version' => 1,
            'body' => 'I agree.',
            'content_hash' => hash('sha256', 'I agree.'),
        ]);

        Livewire::test(Bookings::class)
            ->set('selected', [$confirmed->id, $blocked->id, $held->id])
            ->call('checkInSelected')
            ->assertSee('need a waiver before check-in')
            ->assertSee('is not confirmed');

        $this->assertNull($confirmed->fresh()?->checked_in_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'booking.checked_in')->count());

        WaiverVersion::query()->delete();
        Livewire::test(Bookings::class)
            ->set('selected', [$confirmed->id, $held->id])
            ->call('checkInSelected')
            ->assertSee('Checked in 1.')
            ->assertSee('is not confirmed');

        $this->assertSame(OperationalStatus::CheckedIn, $confirmed->fresh()?->operational_status);
        $this->assertSame($alpha['user']->id, $confirmed->fresh()?->checked_in_by);
        $this->assertSame(BookingStatus::Held, $held->fresh()?->status);
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.checked_in')->count());

        Livewire::test(Bookings::class)
            ->set('selected', [$confirmed->id])
            ->call('checkInSelected')
            ->assertSee('was already checked in');
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.checked_in')->count());
    }

    public function test_bulk_return_marks_checked_out_bookings_returned(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant'], 4, 'UTC');
        $out = $this->checkedOut($alpha, $shop['service']->id, 'Ada Lovelace');
        $waiting = $this->held($alpha, $shop['service']->id, 'Grace Hopper');
        $this->signIn($alpha);
        app(Allocator::class)->confirm($waiting);

        Livewire::test(Bookings::class)
            ->set('selected', [$out->id, $waiting->id])
            ->call('returnSelected')
            ->assertSee('Marked 1 returned.')
            ->assertSee('is not checked out');

        $this->assertNotNull(RentalCheckout::query()->where('booking_id', $out->id)->firstOrFail()->returned_at);
        $this->assertSame(0, RentalCheckout::query()->where('booking_id', $waiting->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'booking.returned')->count());
    }

    public function test_bulk_cancel_confirms_then_refunds_a_card_payment(): void
    {
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant'], 4, 'UTC');
        $held = $this->held($alpha, $shop['service']->id, 'Ada Lovelace');
        $paid = $this->paid($alpha['tenant'], $shop['location'], $shop['service'], 'Grace Hopper');
        $offline = $this->held($alpha, $shop['service']->id, 'Cara Offline');
        $this->signIn($alpha);
        Payment::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $offline->id,
            'method' => 'offline',
            'kind' => 'offline',
            'status' => 'paid',
            'amount_cents' => 4500,
            'fee_cents' => 0,
        ]);

        Livewire::test(Bookings::class)
            ->set('selected', [$held->id])
            ->call('cancelSelected')
            ->assertSee('Confirm the cancellation first.');
        $this->assertSame(BookingStatus::Held, $held->fresh()?->status);

        Livewire::test(Bookings::class)
            ->set('selected', [$held->id, $paid->id, $offline->id])
            ->call('askCancel')
            ->assertSet('confirmingCancel', true)
            ->assertSee('including the booking fee')
            ->assertSee('An offline payment is not refunded.');
        $this->assertSame(BookingStatus::Held, $held->fresh()?->status);
        $this->assertSame(BookingStatus::Confirmed, $paid->fresh()?->status);

        Livewire::test(Bookings::class)
            ->set('selected', [$held->id])
            ->call('askCancel')
            ->set('selected', [$offline->id])
            ->assertSet('confirmingCancel', false)
            ->call('cancelSelected')
            ->assertSee('Confirm the cancellation first.');
        $this->assertSame(BookingStatus::Held, $offline->fresh()?->status);

        Livewire::test(Bookings::class)
            ->set('selected', [$held->id, $paid->id, $offline->id])
            ->call('askCancel')
            ->call('cancelSelected')
            ->assertSee('Cancelled 3.')
            ->assertSee('Undo')
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('Refunded');

        $this->assertSame(BookingStatus::Held, $held->fresh()?->status);
        $this->assertSame(BookingStatus::Confirmed, $paid->fresh()?->status);
        $this->assertSame(BookingStatus::Held, $offline->fresh()?->status);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'booking.cancelled')->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.cancelled')->count());
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.refunded')->count());

        Carbon::setTestNow(now()->addSeconds(11));
        $this->artisan('undo:commit')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertSame(BookingStatus::Cancelled, $held->fresh()?->status);
        $this->assertSame(BookingStatus::Cancelled, $paid->fresh()?->status);
        $this->assertSame(BookingStatus::Cancelled, $offline->fresh()?->status);
        $this->assertSame(PaymentStatus::Refunded, $paid->fresh()?->payment_status);
        $this->assertSame(1, Refund::query()->count());
        $this->assertSame('operator_cancelled', Refund::query()->firstOrFail()->reason);
        $this->assertSame(10_300, Refund::query()->firstOrFail()->customer_refund_cents);
        $this->assertSame(300, Refund::query()->firstOrFail()->application_fee_refund_cents);
        $this->assertTrue($fake->refunds[0]['params']['refund_application_fee']);
        $this->assertSame(3, AuditLog::query()->where('action', 'booking.cancelled')->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'payment.refund')->count());
    }

    public function test_a_card_payment_stays_booked_when_stripe_cannot_refund_it(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant'], 4, 'UTC');
        $paid = $this->paid($alpha['tenant'], $shop['location'], $shop['service'], 'Grace Hopper');
        $this->signIn($alpha);

        Livewire::test(Bookings::class)
            ->set('selected', [$paid->id])
            ->call('askCancel')
            ->call('cancelSelected')
            ->assertSee('was not cancelled')
            ->assertSee('Stripe is not configured');

        $this->assertSame(BookingStatus::Confirmed, $paid->fresh()?->status);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'booking.cancelled')->count());
    }

    public function test_export_downloads_the_selected_rows(): void
    {
        $alpha = $this->workspace('alpha.test');
        $shop = $this->rentalShop($alpha['tenant'], 4, 'UTC');
        $start = CarbonImmutable::parse('2026-06-15 15:00:00', 'UTC');
        $ada = $this->row($alpha['tenant'], $shop['location'], $shop['service'], 'Ada Lovelace', $start);
        $formula = $this->row($alpha['tenant'], $shop['location'], $shop['service'], '=Ada', $start->addHour());
        $leftOut = $this->row($alpha['tenant'], $shop['location'], $shop['service'], 'Left Out', $start->addHours(2));
        $beta = $this->workspace('beta.test');
        $betaShop = $this->rentalShop($beta['tenant'], 4, 'UTC');
        $betaBooking = $this->row($beta['tenant'], $betaShop['location'], $betaShop['service'], 'Beta Customer', $start);
        $this->signIn($alpha);

        $test = Livewire::test(Bookings::class)
            ->set('selected', [$ada->id, $formula->id, $betaBooking->id])
            ->call('exportSelected')
            ->assertFileDownloaded('bookings.csv', null, 'text/csv; charset=UTF-8');
        $csv = base64_decode((string) data_get($test->effects, 'download.content'));

        $this->assertStringContainsString('Ada Lovelace', $csv);
        $this->assertStringContainsString("'=Ada", $csv);
        $this->assertStringContainsString('Kayak rental', $csv);
        $this->assertStringContainsString('2026-06-15 15:00 UTC', $csv);
        $this->assertStringNotContainsString('Left Out', $csv);
        $this->assertStringNotContainsString('Beta Customer', $csv);
        $this->assertNotNull($leftOut->id);
    }

    public function test_permissions_and_another_workspace_are_enforced(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        $shop = $this->rentalShop($alpha['tenant'], 4, 'UTC');
        $booking = $this->held($alpha, $shop['service']->id, 'Ada Lovelace');
        $beta = $this->workspace('beta.test');
        $betaShop = $this->rentalShop($beta['tenant'], 4, 'UTC');
        $betaBooking = $this->held($beta, $betaShop['service']->id, 'Beta Customer');
        $this->signIn($alpha);

        Livewire::test(Bookings::class)
            ->set('selected', [$booking->id, $betaBooking->id])
            ->call('askCancel')
            ->call('cancelSelected')
            ->assertSee('Cancelled 1.')
            ->assertSee('Undo');
        $this->assertSame(BookingStatus::Held, $booking->fresh()?->status);
        Carbon::setTestNow(now()->addSeconds(11));
        $this->artisan('undo:commit')->assertSuccessful();
        Carbon::setTestNow();
        $this->assertSame(BookingStatus::Cancelled, $booking->fresh()?->status);
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertSame(BookingStatus::Held, $betaBooking->fresh()?->status);
        app(TenantManager::class)->set($alpha['tenant']);

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.check_in')->firstOrFail()->id);
        $confirmed = $this->held($alpha, $shop['service']->id, 'Grace Hopper');
        app(Allocator::class)->confirm($confirmed);
        Livewire::test(Bookings::class)
            ->set('selected', [$confirmed->id])
            ->call('checkInSelected')
            ->assertForbidden();
        $this->assertNull($confirmed->fresh()?->checked_in_at);

        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.manage')->firstOrFail()->id);
        $this->actingAs($alpha['user']);
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings'))
            ->assertOk()
            ->assertSee('Export CSV')
            ->assertSee('Save view')
            ->assertDontSee('Mark returned')
            ->assertDontSee('>Check in<', false)
            ->assertDontSee('>Cancel<', false)
            ->assertSee('@media (max-width: 390px)', false);

        $this->signIn($alpha);
        Livewire::test(Bookings::class)
            ->set('selected', [$confirmed->id])
            ->call('returnSelected')
            ->assertForbidden();
        Livewire::test(Bookings::class)
            ->set('selected', [$confirmed->id])
            ->call('askCancel')
            ->assertForbidden();
        $this->assertSame(BookingStatus::Confirmed, $confirmed->fresh()?->status);

        Livewire::test(Bookings::class)
            ->set('selected', [$confirmed->id])
            ->call('exportSelected')
            ->assertFileDownloaded('bookings.csv');

        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id);
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings'))->assertForbidden();
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function signIn(array $workspace): void
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);
    }

    private function service(Tenant $tenant, Location $location, string $name): Service
    {
        app(TenantManager::class)->set($tenant);

        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => 'rental',
        ]);
        app(TenantManager::class)->forget();

        return $service;
    }

    private function row(
        Tenant $tenant,
        Location $location,
        Service $service,
        string $name,
        CarbonImmutable $start,
        BookingStatus $status = BookingStatus::Confirmed,
    ): Booking {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => Str::slug($name).'@example.com',
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => $status,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'participants' => 2,
            'payload_hash' => hash('sha256', $name.$start->utc()->toIso8601String()),
        ]);
        app(TenantManager::class)->forget();

        return $booking;
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function held(array $workspace, string $serviceId, string $name): Booking
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $start = CarbonImmutable::now('UTC')->addDays(2)->setTime(15, 0)->addMinutes(random_int(0, 40));
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $workspace['tenant']->id,
            $serviceId,
            $start,
            $start->addHour(),
            1,
            null,
            $name,
            Str::slug($name).'@example.com',
        ));
        app(TenantManager::class)->forget();

        return $booking;
    }

    /**
     * @param  array{tenant: Tenant, user: User}  $workspace
     */
    private function checkedOut(array $workspace, string $serviceId, string $name): Booking
    {
        app(TenantManager::class)->set($workspace['tenant']);
        $booking = $this->held($workspace, $serviceId, $name);
        app(TenantManager::class)->set($workspace['tenant']);
        $allocator = app(Allocator::class);
        $confirmed = $allocator->confirm($booking);
        $allocator->checkout($confirmed);
        $fresh = $confirmed->fresh();
        app(TenantManager::class)->forget();

        return $fresh instanceof Booking ? $fresh : $confirmed;
    }

    private function paid(Tenant $tenant, Location $location, Service $service, string $name): Booking
    {
        app(TenantManager::class)->set($tenant);
        $booking = $this->row($tenant, $location, $service, $name, CarbonImmutable::parse('2026-06-16 15:00:00', 'UTC'));
        app(TenantManager::class)->set($tenant);
        $booking->status = BookingStatus::Confirmed;
        $booking->payment_status = PaymentStatus::Paid;
        $booking->save();
        PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'payload' => [
                'fee_payer' => 'customer_paid',
                'basis_cents' => 10_000,
                'fee_cents' => 300,
                'application_fee_cents' => 300,
                'tax_cents' => 0,
                'customer_total_cents' => 10_300,
            ],
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 10_300,
            'fee_cents' => 300,
            'stripe_payment_intent_id' => 'pi_'.$booking->id,
        ]);
        PlatformFee::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => 300,
            'refunded_cents' => 0,
            'stripe_fee_id' => 'fee_'.$booking->id,
            'channel' => 'direct',
        ]);
        StripeAccount::query()->create([
            'tenant_id' => $tenant->id,
            'stripe_account_id' => 'acct_test_123',
            'card_payments_status' => 'active',
            'livemode' => false,
        ]);

        return $booking;
    }
}
