<?php

namespace Tests\Feature\Operations;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ResourceFlag;
use App\Enums\ResourceStatus;
use App\Enums\ServiceKind;
use App\Livewire\Staff\Returns;
use App\Models\Allocation;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Pool;
use App\Models\Refund;
use App\Models\RentalCheckout;
use App\Models\RentalReturn;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffReturnsTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_page_lists_todays_due_rentals_and_highlights_minutes_late(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 18:00:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $north = $this->location($alpha['tenant'], 'North dock', 'UTC');
        $south = $this->location($alpha['tenant'], 'South dock', 'UTC');
        $late = $this->openCheckout($alpha['tenant'], $north, 'Late Guest', 'Harbor kayak', '2026-06-16 15:00:00', '2026-06-16 17:00:00');
        $onTime = $this->openCheckout($alpha['tenant'], $north, 'On Time Guest', 'Spare kayak', '2026-06-16 16:00:00', '2026-06-16 20:00:00');
        $yesterday = $this->openCheckout($alpha['tenant'], $north, 'Yesterday Guest', 'Old kayak', '2026-06-15 11:00:00', '2026-06-15 12:00:00');
        $tomorrow = $this->openCheckout($alpha['tenant'], $north, 'Tomorrow Guest', 'Future kayak', '2026-06-17 11:00:00', '2026-06-17 12:00:00');
        $southCheckout = $this->openCheckout($alpha['tenant'], $south, 'South Guest', 'South kayak', '2026-06-16 15:00:00', '2026-06-16 17:30:00');
        $returned = $this->openCheckout($alpha['tenant'], $north, 'Already Back', 'Returned kayak', '2026-06-16 14:00:00', '2026-06-16 16:00:00');
        $this->useTenant($alpha['tenant']);
        $returned['checkout']->returned_at = CarbonImmutable::parse('2026-06-16 16:05:00', 'UTC');
        $returned['checkout']->save();
        $betaDock = $this->location($beta['tenant'], 'Beta dock', 'UTC');
        $this->openCheckout($beta['tenant'], $betaDock, 'Beta Guest', 'Beta kayak', '2026-06-16 15:00:00', '2026-06-16 17:00:00');
        app(TenantManager::class)->forget();

        $page = $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/returns'))
            ->assertOk()
            ->assertSee('Due back today')
            ->assertSee('Late Guest')
            ->assertSee('60 minutes late')
            ->assertSee('On Time Guest')
            ->assertSee('Yesterday Guest')
            ->assertSee('1800 minutes late')
            ->assertSee('South Guest')
            ->assertSee('href="/staff/returns"', false)
            ->assertDontSee('Tomorrow Guest')
            ->assertDontSee('Already Back')
            ->assertDontSee('Beta Guest');

        $html = (string) $page->getContent();
        $this->assertStringContainsString('data-checkout="'.$late['checkout']->id.'" data-overdue="yes" data-minutes="60"', $html);
        $this->assertStringContainsString('data-checkout="'.$onTime['checkout']->id.'" data-overdue="no" data-minutes="0"', $html);
        $this->assertStringContainsString('data-checkout="'.$yesterday['checkout']->id.'"', $html);
        $this->assertStringNotContainsString('data-checkout="'.$tomorrow['checkout']->id.'"', $html);
        $this->assertStringNotContainsString('data-checkout="'.$returned['checkout']->id.'"', $html);
        $this->assertStringContainsString('@media (max-width: 390px)', $html);
        $this->assertSame(1, preg_match('/<nav class="tab-bar"[\s\S]*?<\/nav>/', $html, $nav));
        $this->assertSame(5, substr_count($nav[0], '<a '));
        $this->assertStringNotContainsString('Returns', $nav[0]);

        $this->get($this->tenantUrl('alpha.test', '/staff/returns?location='.$south->id))
            ->assertOk()
            ->assertSee('South Guest')
            ->assertDontSee('Late Guest');
        $this->get($this->tenantUrl('alpha.test', '/staff/returns?location='.$betaDock->id))->assertNotFound();
        $this->get($this->tenantUrl('alpha.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('href="/staff/returns"', false);
        $this->get($this->tenantUrl('alpha.test', '/staff'))
            ->assertOk()
            ->assertSee('data-overdue="3"', false)
            ->assertSee('href="/staff/returns"', false);

        $this->assertStringContainsString('data-checkout="'.$southCheckout['checkout']->id.'"', (string) $this->get($this->tenantUrl('alpha.test', '/staff/returns?location='.$south->id))->getContent());
    }

    public function test_a_return_records_minutes_late_and_does_not_move_money(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 19:30:00', 'UTC'));
        $alpha = $this->workspace('alpha.test');
        $north = $this->location($alpha['tenant'], 'North dock', 'UTC');
        $this->useTenant($alpha['tenant']);
        TenantSetting::query()->update(['late_fee_per_minute_cents' => 80]);
        $damaged = $this->openCheckout($alpha['tenant'], $north, 'Damage Guest', 'Cracked hull', '2026-06-16 16:00:00', '2026-06-16 19:00:00', 10);
        $attention = $this->openCheckout($alpha['tenant'], $north, 'Scuff Guest', 'Scuffed board', '2026-06-16 16:00:00', '2026-06-16 19:30:00');
        $pool = $this->poolCheckout($alpha['tenant'], $north, 'Pool Guest', 'Rental pool', '2026-06-16 16:00:00', '2026-06-16 19:00:00');
        $future = $this->futureBooking($alpha['tenant'], $north, $damaged['asset'], 'Future Guest');
        $cleared = $this->openCheckout($alpha['tenant'], $north, 'Clear Guest', 'Fine boat', '2026-06-16 16:00:00', '2026-06-16 21:00:00');
        $cleared['asset']->condition_flag = ResourceFlag::NeedsAttention;
        $cleared['asset']->save();
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);
        Livewire::test(Returns::class)
            ->set('returnedAt.'.$damaged['checkout']->id, '2026-06-16T19:20')
            ->set('condition.'.$damaged['checkout']->id, 'damaged')
            ->set('note.'.$damaged['checkout']->id, 'Hull crack')
            ->call('recordReturn', $damaged['checkout']->id)
            ->assertHasNoErrors()
            ->assertSee('20 minutes late');

        $this->useTenant($alpha['tenant']);
        $damaged['checkout']->refresh();
        $this->assertSame('2026-06-16 19:20:00', $damaged['checkout']->returned_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($damaged['checkout']->late_fee_cents);
        $row = RentalReturn::query()->where('rental_checkout_id', $damaged['checkout']->id)->firstOrFail();
        $this->assertSame('damaged', $row->condition->value);
        $this->assertSame(20, $row->minutes_late);
        $this->assertSame('Hull crack', $row->notes);
        $this->assertSame($alpha['user']->id, $row->recorded_by);
        $this->assertSame(ResourceStatus::Maintenance, $damaged['asset']->refresh()->status);
        $this->assertSame(ResourceFlag::Damaged, $damaged['asset']->condition_flag);
        $this->assertSame('Asset is maintenance.', $future->refresh()->attention_reason);
        $this->assertSame(OperationalStatus::Returned, $damaged['booking']->refresh()->operational_status);
        $this->assertSame('2026-06-16 19:00:00', $damaged['allocation']->refresh()->ends_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, Refund::withoutGlobalScope(TenantScope::class)->count());
        $audit = $this->audit($alpha['tenant'], 'rental.returned');
        $this->assertSame($row->id, $audit->subject_id);
        $this->assertSame(20, $audit->properties['minutes_late']);
        $this->assertSame('damaged', $audit->properties['condition']);
        $this->assertSame($alpha['user']->id, $audit->actor_user_id);
        $this->assertArrayNotHasKey('notes', $audit->properties ?? []);
        $this->assertStringNotContainsString('Hull crack', (string) json_encode($audit->properties));
        $this->assertSame('maintenance', $this->audit($alpha['tenant'], 'asset.updated')->properties['status']);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.returned')->where('booking_id', $damaged['booking']->id)->count());

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$attention['checkout']->id, '2026-06-16T19:00')
            ->set('condition.'.$attention['checkout']->id, 'needs_attention')
            ->set('note.'.$attention['checkout']->id, '')
            ->call('recordReturn', $attention['checkout']->id)
            ->assertHasNoErrors();
        $attention['asset']->refresh();
        $this->assertSame(ResourceStatus::Active, $attention['asset']->status);
        $this->assertSame(ResourceFlag::NeedsAttention, $attention['asset']->condition_flag);
        $this->assertNull(RentalReturn::query()->where('rental_checkout_id', $attention['checkout']->id)->firstOrFail()->notes);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$pool['checkout']->id, '2026-06-16T19:05')
            ->set('condition.'.$pool['checkout']->id, 'needs_attention')
            ->call('recordReturn', $pool['checkout']->id)
            ->assertHasNoErrors();
        $pool['pool']->refresh();
        $this->assertSame(ResourceStatus::Active, $pool['pool']->status);
        $this->assertSame(ResourceFlag::NeedsAttention, $pool['pool']->condition_flag);
        $this->assertSame(5, RentalReturn::query()->where('rental_checkout_id', $pool['checkout']->id)->firstOrFail()->minutes_late);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$cleared['checkout']->id, '2026-06-16T18:00')
            ->set('condition.'.$cleared['checkout']->id, 'good')
            ->call('recordReturn', $cleared['checkout']->id)
            ->assertHasNoErrors()
            ->assertSee('Returned on time.');
        $this->assertNull($cleared['asset']->refresh()->condition_flag);
        $this->assertSame(0, RentalReturn::query()->where('rental_checkout_id', $cleared['checkout']->id)->firstOrFail()->minutes_late);
        $this->assertSame('2026-06-16 18:00:00', $cleared['allocation']->refresh()->ends_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(0, Payment::withoutGlobalScope(TenantScope::class)->count());

        $schedule = (string) $this->get($this->tenantUrl('alpha.test', '/staff/schedule?date=2026-06-16'))->assertOk()->getContent();
        $this->assertStringContainsString('Needs attention', $schedule);
        $this->assertStringContainsString('Damaged', $schedule);
        $this->assertMatchesRegularExpression('/data-resource="asset:'.$attention['asset']->id.'"[^>]*data-flag="needs_attention"/', $schedule);
        $this->assertMatchesRegularExpression('/data-resource="asset:'.$damaged['asset']->id.'"[^>]*data-flag="damaged"/', $schedule);
        $this->assertMatchesRegularExpression('/data-resource="pool:'.$pool['pool']->id.'"[^>]*data-flag="needs_attention"/', $schedule);
        $this->assertMatchesRegularExpression('/data-resource="asset:'.$cleared['asset']->id.'"[^>]*data-flag=""/', $schedule);
    }

    public function test_each_resource_returns_on_its_own_and_the_action_is_guarded(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 19:50:00', 'UTC'));
        $alpha = $this->workspace('alpha.test', 'staff');
        $beta = $this->workspace('beta.test');
        $north = $this->location($alpha['tenant'], 'North dock', 'UTC');
        $first = $this->openCheckout($alpha['tenant'], $north, 'First Guest', 'Kayak one', '2026-06-16 16:00:00', '2026-06-16 19:00:00');
        $secondAsset = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $north->id,
            'name' => 'Kayak two',
        ]);
        $this->useTenant($alpha['tenant']);
        $secondCheckout = RentalCheckout::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $first['booking']->id,
            'asset_id' => $secondAsset->id,
            'quantity' => 1,
            'checked_out_at' => '2026-06-16 16:00:00',
            'due_at' => '2026-06-16 19:00:00',
        ]);
        Allocation::query()->create([
            'tenant_id' => $alpha['tenant']->id,
            'booking_id' => $first['booking']->id,
            'asset_id' => $secondAsset->id,
            'quantity' => 1,
            'starts_at' => '2026-06-16 16:00:00',
            'ends_at' => '2026-06-16 19:00:00',
        ]);
        $betaDock = $this->location($beta['tenant'], 'Beta dock', 'UTC');
        $foreign = $this->openCheckout($beta['tenant'], $betaDock, 'Beta Guest', 'Beta boat', '2026-06-16 16:00:00', '2026-06-16 19:00:00');
        app(TenantManager::class)->forget();

        $this->actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);
        Livewire::test(Returns::class)
            ->set('returnedAt.'.$first['checkout']->id, '2026-06-16T19:10')
            ->set('condition.'.$first['checkout']->id, 'good')
            ->call('recordReturn', $first['checkout']->id)
            ->assertHasNoErrors();

        $this->useTenant($alpha['tenant']);
        $this->assertNotNull($first['checkout']->refresh()->returned_at);
        $this->assertNull($secondCheckout->refresh()->returned_at);
        $this->assertNotSame(OperationalStatus::Returned, $first['booking']->refresh()->operational_status);
        $this->assertSame(0, NotificationHook::query()->where('kind', 'booking.returned')->count());

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$secondCheckout->id, '2026-06-16T19:40')
            ->set('condition.'.$secondCheckout->id, 'good')
            ->call('recordReturn', $secondCheckout->id)
            ->assertHasNoErrors();
        $this->assertSame(40, RentalReturn::query()->where('rental_checkout_id', $secondCheckout->id)->firstOrFail()->minutes_late);
        $this->assertSame(OperationalStatus::Returned, $first['booking']->refresh()->operational_status);
        $this->assertSame(1, NotificationHook::query()->where('kind', 'booking.returned')->where('booking_id', $first['booking']->id)->count());
        $this->assertNull($secondCheckout->refresh()->late_fee_cents);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$first['checkout']->id, '2026-06-16T19:10')
            ->set('condition.'.$first['checkout']->id, 'good')
            ->call('recordReturn', $first['checkout']->id)
            ->assertSee('This resource is already back.');
        $this->assertSame(1, RentalReturn::query()->where('rental_checkout_id', $first['checkout']->id)->count());

        $early = $this->openCheckout($alpha['tenant'], $north, 'Early Guest', 'Early boat', '2026-06-16 16:00:00', '2026-06-16 19:00:00');
        Livewire::test(Returns::class)
            ->set('returnedAt.'.$early['checkout']->id, '2026-06-17T12:00')
            ->set('condition.'.$early['checkout']->id, 'good')
            ->call('recordReturn', $early['checkout']->id)
            ->assertHasErrors(['returnedAt.'.$early['checkout']->id]);
        $this->assertNull($early['checkout']->refresh()->returned_at);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$early['checkout']->id, '2026-06-16T14:00')
            ->set('condition.'.$early['checkout']->id, 'good')
            ->call('recordReturn', $early['checkout']->id)
            ->assertHasErrors(['returnedAt.'.$early['checkout']->id]);
        $this->assertNull($early['checkout']->refresh()->returned_at);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$early['checkout']->id, '2026-06-16T18:00')
            ->set('condition.'.$early['checkout']->id, 'missing')
            ->call('recordReturn', $early['checkout']->id)
            ->assertHasErrors(['condition.'.$early['checkout']->id]);

        Livewire::test(Returns::class)
            ->set('returnedAt.'.$early['checkout']->id, '2026-06-16T18:00')
            ->set('condition.'.$early['checkout']->id, 'good')
            ->set('note.'.$early['checkout']->id, str_repeat('a', 501))
            ->call('recordReturn', $early['checkout']->id)
            ->assertHasErrors(['note.'.$early['checkout']->id]);
        $this->assertNull($early['checkout']->refresh()->returned_at);

        Livewire::test(Returns::class)->call('recordReturn', $foreign['checkout']->id)->assertNotFound();
        $this->useTenant($beta['tenant']);
        $this->assertNull($foreign['checkout']->refresh()->returned_at);
        app(TenantManager::class)->forget();

        Sanctum::actingAs($alpha['user']);
        $this->useTenant($alpha['tenant']);
        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/bookings/'.$early['booking']->id.'/returns'), [
            'conditions' => [
                ['asset_id' => $early['asset']->id, 'condition' => 'good'],
            ],
        ])->assertStatus(422)->assertJsonValidationErrors([
            'conditions.0.condition' => 'Condition must be ok, damaged, or missing.',
        ]);
        $this->assertNull($early['checkout']->refresh()->returned_at);

        $role = Role::query()->where('slug', 'staff')->firstOrFail();
        $role->permissions()->detach(Permission::query()->where('slug', 'bookings.check_in')->firstOrFail()->id);
        $this->actingAs($alpha['user'])
            ->get($this->tenantUrl('alpha.test', '/staff/returns'))
            ->assertForbidden();
        $this->useTenant($alpha['tenant']);
        Livewire::test(Returns::class)->assertForbidden();

        $viewer = User::factory()->create();
        Membership::factory()->role('staff')->create([
            'tenant_id' => $beta['tenant']->id,
            'user_id' => $viewer->id,
        ]);
        $this->actingAs($viewer)
            ->get($this->tenantUrl('alpha.test', '/staff/returns'))
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->get($this->tenantUrl('alpha.test', '/staff/returns'))->assertRedirect('/staff/login');
    }

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
     * @return array{booking: Booking, checkout: RentalCheckout, asset: Asset, allocation: Allocation}
     */
    private function openCheckout(
        Tenant $tenant,
        Location $location,
        string $customerName,
        string $assetName,
        string $checkedOutAt,
        string $dueAt,
        int $turnaround = 0,
    ): array {
        $this->useTenant($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $assetName.' rental',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '.', $customerName)).'@example.com',
        ]);
        $asset = Asset::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $assetName,
            'turnaround_minutes' => $turnaround,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::InProgress,
            'starts_at' => $checkedOutAt,
            'ends_at' => $dueAt,
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerName.$assetName.$dueAt),
        ]);
        $checkout = RentalCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'checked_out_at' => $checkedOutAt,
            'due_at' => $dueAt,
        ]);
        $allocation = Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'starts_at' => $checkedOutAt,
            'ends_at' => $dueAt,
        ]);

        return compact('booking', 'checkout', 'asset', 'allocation');
    }

    /**
     * @return array{booking: Booking, checkout: RentalCheckout, pool: Pool}
     */
    private function poolCheckout(
        Tenant $tenant,
        Location $location,
        string $customerName,
        string $poolName,
        string $checkedOutAt,
        string $dueAt,
    ): array {
        $this->useTenant($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $poolName.' rental',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
            'email' => strtolower(str_replace(' ', '.', $customerName)).'@example.com',
        ]);
        $pool = Pool::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $poolName,
            'quantity' => 4,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::InProgress,
            'starts_at' => $checkedOutAt,
            'ends_at' => $dueAt,
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerName.$poolName),
        ]);
        $checkout = RentalCheckout::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'pool_id' => $pool->id,
            'quantity' => 1,
            'checked_out_at' => $checkedOutAt,
            'due_at' => $dueAt,
        ]);
        Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'pool_id' => $pool->id,
            'quantity' => 1,
            'starts_at' => $checkedOutAt,
            'ends_at' => $dueAt,
        ]);

        return compact('booking', 'checkout', 'pool');
    }

    private function futureBooking(Tenant $tenant, Location $location, Asset $asset, string $customerName): Booking
    {
        $this->useTenant($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Later rental',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
            'email' => 'future@example.com',
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-17 15:00:00',
            'ends_at' => '2026-06-17 16:00:00',
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerName),
        ]);
        Allocation::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'asset_id' => $asset->id,
            'quantity' => 1,
            'starts_at' => '2026-06-17 15:00:00',
            'ends_at' => '2026-06-17 16:00:00',
        ]);

        return $booking;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    private function audit(Tenant $tenant, string $action): AuditLog
    {
        return AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->where('action', $action)
            ->latest('created_at')
            ->firstOrFail();
    }
}
