<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\OperatorFeePricing;
use App\Enums\OperatorFeeScope;
use App\Enums\PaymentStatus;
use App\Livewire\Staff\Setup\Locations;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\BookingOperatorFee;
use App\Models\Domain;
use App\Models\Location;
use App\Models\Membership;
use App\Models\OperatorFee;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffLocationArchiveTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    /**
     * @return array{tenant: Tenant, domain: Domain, user: User, membership: Membership}
     */
    private function signedIn(string $host = 'alpha.test', string $role = 'owner'): array
    {
        $workspace = $this->workspace($host, $role, ['name' => 'Alpha Kayaks']);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        return $workspace;
    }

    public function test_an_owner_archives_a_location_by_typing_its_name_and_money_rows_stay(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $south = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South pier',
            'timezone' => 'UTC',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South kayak',
            'quantity' => 3,
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South boat',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South rental',
            'kind' => 'rental',
        ]);
        $past = $this->booking($south, $service, BookingStatus::Confirmed, '2026-06-01 15:00:00', '2026-06-01 16:00:00', PaymentStatus::Paid);
        $this->booking($south, $service, BookingStatus::Cancelled, '2026-07-01 15:00:00', '2026-07-01 16:00:00', PaymentStatus::Unpaid);
        $pending = $this->booking($south, $service, BookingStatus::PendingPayment, '2026-07-02 15:00:00', '2026-07-02 16:00:00', PaymentStatus::Unpaid);
        $money = $this->money($workspace['tenant'], $past, $south);
        $beta = $this->workspace('beta.test');
        $betaLocation = Location::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta dock',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->actingAs($workspace['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/locations'))
            ->assertOk()
            ->assertSee('class="page locations-page"', false)
            ->assertSee('max-width: 390px', false)
            ->assertSee('.locations-page .delete-location input', false)
            ->assertSee('askDelete', false);

        app(TenantManager::class)->set($workspace['tenant']);
        $before = $this->moneyRows();
        $this->assertNotEmpty($before['payments']);
        $this->assertNotEmpty($before['refunds']);
        $this->assertNotEmpty($before['platform_fees']);
        $this->assertNotEmpty($before['platform_fee_refunds']);
        $this->assertNotEmpty($before['snapshots']);
        $this->assertNotEmpty($before['operator_fees']);
        $this->assertNotEmpty($before['booking_operator_fees']);

        Livewire::test(Locations::class)
            ->call('askDelete', $south->id)
            ->assertSee('Delete South pier?')
            ->assertSee('South pier has 0 upcoming bookings and 2 pieces of equipment.')
            ->assertSee('Type South pier to delete it.')
            ->assertDontSee('cannot be deleted')
            ->set('confirmName', 'south pier')
            ->call('archive')
            ->assertHasErrors('confirmName')
            ->assertSee('Type South pier to delete this location.');

        $this->assertSame($before, $this->moneyRows());
        $this->assertNull($this->storedLocation($south->id)->deleted_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'location.archived')->count());

        Livewire::test(Locations::class)
            ->call('askDelete', $south->id)
            ->set('confirmName', 'South pier')
            ->call('archive')
            ->assertHasNoErrors()
            ->assertSee('South pier was deleted. Past bookings stay on it.');

        $archived = $this->storedLocation($south->id);
        $this->assertNotNull($archived->deleted_at);
        $this->assertNull(Location::query()->find($south->id));
        $this->assertSame(2, Location::withTrashed()->count());
        $this->assertSame(1, Location::query()->count());
        $this->assertSame($shop['location']->id, Location::query()->value('id'));

        $past->refresh();
        $pending->refresh();
        $this->assertSame($south->id, $past->location_id);
        $this->assertSame(BookingStatus::Confirmed, $past->status);
        $this->assertSame(PaymentStatus::Paid, $past->payment_status);
        $this->assertSame('South pier', $past->location?->name);
        $this->assertSame($south->id, $pending->location_id);
        $this->assertSame($before, $this->moneyRows());
        $this->assertSame($money['payment']->id, Payment::query()->value('id'));
        $this->assertSame($south->id, $pool->fresh()?->location_id);
        $this->assertNull($pool->fresh()?->deleted_at);
        $this->assertSame($south->id, $asset->fresh()?->location_id);
        $this->assertNull($asset->fresh()?->deleted_at);
        $this->assertSame($south->id, OperatorFee::query()->value('location_id'));

        $audit = AuditLog::query()->where('action', 'location.archived')->firstOrFail();
        $this->assertSame($workspace['user']->id, $audit->actor_user_id);
        $this->assertSame(Location::class, $audit->subject_type);
        $this->assertSame($south->id, $audit->subject_id);
        $this->assertSame('South pier', $audit->properties['name'] ?? null);
        $this->assertSame(0, $audit->properties['upcoming_bookings'] ?? null);
        $this->assertSame(2, $audit->properties['equipment'] ?? null);
        $this->assertNull($this->storedLocation($betaLocation->id)->deleted_at);

        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        $this->get($this->tenantUrl('alpha.test', '/book/'.$token))
            ->assertOk()
            ->assertSee('Kayak rental')
            ->assertDontSee('South rental');
        $this->get($this->tenantUrl('alpha.test', '/book/'.$token.'/services/'.$service->id))->assertNotFound();
        $this->post($this->tenantUrl('alpha.test', '/book/'.$token.'/services/'.$service->id), [])->assertNotFound();

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/locations'))
            ->assertOk()
            ->assertSee('North dock')
            ->assertDontSee('South pier');
        $this->get($this->tenantUrl('alpha.test', '/staff/calendar'))
            ->assertOk()
            ->assertSee('North dock')
            ->assertDontSee('South pier');
        $this->get($this->tenantUrl('alpha.test', '/staff/bookings/new'))
            ->assertOk()
            ->assertSee('Kayak rental')
            ->assertDontSee('South rental')
            ->assertDontSee('South pier');
    }

    public function test_upcoming_held_or_confirmed_bookings_block_the_delete_and_say_why(): void
    {
        $workspace = $this->signedIn();
        $this->rentalShop($workspace['tenant'], 4, 'UTC');
        $south = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'South pier',
            'timezone' => 'UTC',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South rental',
            'kind' => 'rental',
        ]);
        $booking = $this->booking($south, $service, BookingStatus::Held, '2026-06-14 10:00:00', '2026-06-16 10:00:00', PaymentStatus::Unpaid);

        Livewire::test(Locations::class)
            ->call('askDelete', $south->id)
            ->assertSee('South pier has 1 upcoming booking and 0 pieces of equipment.')
            ->assertSee('This location has 1 upcoming booking, so it cannot be deleted while those bookings are held or confirmed.')
            ->assertDontSee('Type South pier to delete it.')
            ->set('confirmName', 'South pier')
            ->call('archive')
            ->assertHasErrors('confirmName');

        $this->assertNull($this->storedLocation($south->id)->deleted_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'location.archived')->count());

        $booking->status = BookingStatus::Confirmed;
        $booking->save();

        Livewire::test(Locations::class)
            ->call('askDelete', $south->id)
            ->assertSee('This location has 1 upcoming booking, so it cannot be deleted while those bookings are held or confirmed.');
        $this->assertNull($this->storedLocation($south->id)->deleted_at);

        $booking->ends_at = Carbon::parse('2026-06-14 11:00:00', 'UTC');
        $booking->save();

        Livewire::test(Locations::class)
            ->call('askDelete', $south->id)
            ->assertSee('South pier has 0 upcoming bookings and 0 pieces of equipment.')
            ->assertSee('Type South pier to delete it.')
            ->assertDontSee('cannot be deleted while those bookings are held or confirmed.');
    }

    public function test_the_last_location_cannot_be_deleted(): void
    {
        $workspace = $this->signedIn();
        $shop = $this->rentalShop($workspace['tenant'], 4, 'UTC');

        Livewire::test(Locations::class)
            ->call('askDelete', $shop['location']->id)
            ->assertSee('North dock has 0 upcoming bookings and 1 piece of equipment.')
            ->assertSee('This is the only location left, so it cannot be deleted.')
            ->assertDontSee('Type North dock to delete it.')
            ->set('confirmName', 'North dock')
            ->call('archive')
            ->assertHasErrors('confirmName')
            ->assertSee('This is the only location left, so it cannot be deleted.');

        $this->assertNull($this->storedLocation($shop['location']->id)->deleted_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'location.archived')->count());
        $this->assertSame(1, Location::query()->count());
    }

    public function test_staff_cannot_delete_a_location_and_a_manager_can(): void
    {
        $staff = $this->signedIn('staff.test', 'staff');
        $this->rentalShop($staff['tenant'], 4, 'UTC');
        $extra = Location::factory()->create([
            'tenant_id' => $staff['tenant']->id,
            'name' => 'South pier',
        ]);

        $this->get($this->tenantUrl('staff.test', '/staff/setup/locations'))
            ->assertOk()
            ->assertSee('North dock')
            ->assertSee('South pier')
            ->assertDontSee('Delete')
            ->assertDontSee('askDelete');

        app(TenantManager::class)->set($staff['tenant']);
        Livewire::test(Locations::class)
            ->call('askDelete', $extra->id)
            ->assertForbidden();
        Livewire::test(Locations::class)
            ->set('deletingId', $extra->id)
            ->set('confirmName', 'South pier')
            ->call('archive')
            ->assertForbidden();
        $this->assertNull($this->storedLocation($extra->id)->deleted_at);

        $manager = $this->signedIn('manager.test', 'manager');
        $this->rentalShop($manager['tenant'], 4, 'UTC');
        $south = Location::factory()->create([
            'tenant_id' => $manager['tenant']->id,
            'name' => 'South pier',
        ]);
        app(TenantManager::class)->set($manager['tenant']);

        Livewire::test(Locations::class)
            ->call('askDelete', $south->id)
            ->set('confirmName', 'South pier')
            ->call('archive')
            ->assertHasNoErrors()
            ->assertSee('South pier was deleted. Past bookings stay on it.');

        $this->assertNotNull($this->storedLocation($south->id)->deleted_at);
        $this->assertNull($this->storedLocation($extra->id)->deleted_at);
        $audit = AuditLog::query()->where('action', 'location.archived')->firstOrFail();
        $this->assertSame($manager['user']->id, $audit->actor_user_id);
        $this->assertSame($manager['tenant']->id, $audit->tenant_id);
    }

    private function booking(
        Location $location,
        Service $service,
        BookingStatus $status,
        string $starts,
        string $ends,
        PaymentStatus $payment,
    ): Booking {
        return Booking::query()->create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'status' => $status,
            'payment_status' => $payment,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'participants' => 2,
            'payload_hash' => hash('sha256', $location->id.$status->value.$starts),
        ]);
    }

    /**
     * @return array{payment: Payment}
     */
    private function money(Tenant $tenant, Booking $booking, Location $location): array
    {
        $snapshot = PricingSnapshot::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'payload' => [
                'fee_payer' => 'customer_paid',
                'basis_cents' => 10000,
                'fee_cents' => 300,
                'tax_cents' => 0,
                'customer_total_cents' => 10300,
            ],
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 10300,
            'fee_cents' => 300,
            'stripe_payment_intent_id' => 'pi_south_pier',
        ]);
        Refund::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'reason' => 'customer_cancelled_partial_refund',
            'rules_version' => 'spec-1.0/7.7',
            'customer_refund_cents' => 400,
            'customer_fee_refund_cents' => 12,
            'application_fee_refund_cents' => 12,
            'refund_subtotal_cents' => 400,
            'refund_tax_cents' => 0,
        ]);
        $fee = PlatformFee::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'payment_id' => $payment->id,
            'amount_cents' => 300,
            'refunded_cents' => 12,
            'stripe_fee_id' => 'fee_south_pier',
            'channel' => 'direct',
        ]);
        PlatformFeeRefund::query()->create([
            'tenant_id' => $tenant->id,
            'platform_fee_id' => $fee->id,
            'amount_cents' => 12,
            'reason' => 'Courtesy',
            'rule' => PlatformFeeRefund::RULE,
        ]);
        $catalog = OperatorFee::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Cleaning',
            'pricing' => OperatorFeePricing::Flat,
            'amount_cents' => 500,
            'active' => true,
        ]);
        BookingOperatorFee::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'operator_fee_id' => $catalog->id,
            'name' => 'Cleaning',
            'scope' => OperatorFeeScope::Service,
            'pricing' => OperatorFeePricing::Flat,
            'amount_cents' => 500,
            'quantity' => 1,
            'total_cents' => 500,
        ]);
        $this->assertNotNull($snapshot->id);

        return ['payment' => $payment];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function moneyRows(): array
    {
        return [
            'payments' => $this->rows(Payment::class, ['id', 'booking_id', 'amount_cents', 'fee_cents', 'status', 'method', 'kind']),
            'refunds' => $this->rows(Refund::class, ['id', 'booking_id', 'payment_id', 'customer_refund_cents', 'customer_fee_refund_cents', 'application_fee_refund_cents', 'refund_subtotal_cents', 'refund_tax_cents', 'reason']),
            'platform_fees' => $this->rows(PlatformFee::class, ['id', 'booking_id', 'payment_id', 'amount_cents', 'refunded_cents', 'channel']),
            'platform_fee_refunds' => $this->rows(PlatformFeeRefund::class, ['id', 'platform_fee_id', 'amount_cents', 'reason', 'rule']),
            'snapshots' => $this->rows(PricingSnapshot::class, ['id', 'booking_id', 'currency', 'rules_version', 'payload']),
            'operator_fees' => $this->rows(OperatorFee::class, ['id', 'location_id', 'service_id', 'amount_cents', 'name']),
            'booking_operator_fees' => $this->rows(BookingOperatorFee::class, ['id', 'booking_id', 'operator_fee_id', 'total_cents', 'amount_cents', 'quantity']),
            'price_rules' => $this->rows(PriceRule::class, ['id', 'service_id', 'amount_cents', 'kind']),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  list<string>  $columns
     * @return list<array<string, mixed>>
     */
    private function rows(string $model, array $columns): array
    {
        return $model::query()->orderBy('id')->get($columns)->map(fn (Model $row): array => $row->only($columns))->values()->all();
    }

    private function storedLocation(string $id): Location
    {
        $location = Location::withoutGlobalScope(TenantScope::class)->withTrashed()->find($id);
        $this->assertInstanceOf(Location::class, $location);

        return $location;
    }
}
