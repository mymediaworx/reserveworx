<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\PaymentStatus;
use App\Livewire\Staff\BookingShow;
use App\Models\Allocation;
use App\Models\Booking;
use App\Models\Hold;
use App\Models\Membership;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class PaymentExceptionMoveTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_staff_move_a_payment_exception_onto_a_free_time_and_leave_the_payment_alone(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1);
        $lost = $this->lostSlot($alpha['tenant']->id, $shop['service']->id);
        $snapshotId = $lost['snapshot']->id;
        $payload = $lost['snapshot']->payload;
        $occupantAllocationId = $lost['occupant_allocation_id'];
        $this->actingAs($alpha['user']);

        $css = file_get_contents(resource_path('ui/app.css'));
        $this->assertIsString($css);
        $this->assertStringContainsString('.recover-time .field input', $css);
        $this->assertStringContainsString('overflow-wrap: anywhere', $css);
        $this->assertStringContainsString('@media (max-width: 390px)', $css);

        $page = Livewire::test(BookingShow::class, ['booking' => $lost['booking']->id])
            ->assertSeeHtml('data-needs-attention="payment_exception"')
            ->assertSee('Paid, but the time was no longer free.')
            ->assertSee('Move this booking to a free time below.')
            ->assertSeeHtml('class="panel recover-time"')
            ->assertSee('Reschedule')
            ->assertDontSee('Cancel booking')
            ->assertDontSee('Extend rental')
            ->assertDontSee('No-show')
            ->assertDontSee('full refund')
            ->set('rescheduleStart', '2026-07-06T10:00')
            ->set('rescheduleEnd', '2026-07-06T11:00')
            ->call('reschedule')
            ->assertSee('That time is no longer available.');

        app(TenantManager::class)->set($alpha['tenant']);
        $stuck = Booking::query()->findOrFail($lost['booking']->id);
        $this->assertSame(BookingStatus::PaymentException, $stuck->status);
        $this->assertSame(PaymentStatus::Paid, $stuck->payment_status);
        $this->assertSame(0, Allocation::query()->where('booking_id', $stuck->id)->count());
        $this->assertSame($occupantAllocationId, Allocation::query()->findOrFail($occupantAllocationId)->id);
        $this->assertSame(0, NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$stuck->id)->count());
        $this->assertSame(0, Refund::query()->count());

        $page->set('rescheduleStart', '2026-07-06T12:00')
            ->set('rescheduleEnd', '2026-07-06T13:00')
            ->call('reschedule')
            ->assertSee('Rescheduled.');

        app(TenantManager::class)->set($alpha['tenant']);
        $moved = Booking::query()->findOrFail($lost['booking']->id);
        $allocation = Allocation::query()->where('booking_id', $moved->id)->sole();
        $snapshot = PricingSnapshot::query()->findOrFail($snapshotId);
        $payment = Payment::query()->where('booking_id', $moved->id)->sole();
        $fee = PlatformFee::query()->where('booking_id', $moved->id)->sole();
        $this->assertSame(BookingStatus::Confirmed, $moved->status);
        $this->assertSame(PaymentStatus::Paid, $moved->payment_status);
        $this->assertSame('2026-07-06 16:00:00', $allocation->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-06 17:00:00', $allocation->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-06 14:00:00', Allocation::query()->findOrFail($occupantAllocationId)->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($snapshotId, $snapshot->id);
        $this->assertSame($payload, $snapshot->payload);
        $this->assertSame(10300, $payment->amount_cents);
        $this->assertSame(300, $payment->fee_cents);
        $this->assertSame(300, $fee->amount_cents);
        $this->assertSame(0, $fee->refunded_cents);
        $this->assertSame(0, Refund::query()->count());
        $this->assertSame(2, PriceRule::query()->count());
        $this->assertSame(1, NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$moved->id)->count());

        Livewire::test(BookingShow::class, ['booking' => $moved->id])
            ->set('rescheduleStart', '2026-07-06T13:00')
            ->set('rescheduleEnd', '2026-07-06T14:00')
            ->call('reschedule')
            ->assertSee('Rescheduled.');

        app(TenantManager::class)->set($alpha['tenant']);
        $again = Booking::query()->findOrFail($moved->id);
        $this->assertSame(BookingStatus::Confirmed, $again->status);
        $this->assertSame('2026-07-06 17:00:00', $again->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(10300, Payment::query()->where('booking_id', $again->id)->sole()->amount_cents);
        $this->assertSame(1, NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$again->id)->count());
        $this->assertSame(0, Refund::query()->count());
    }

    public function test_a_viewer_cannot_move_a_payment_exception(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1);
        $lost = $this->lostSlot($alpha['tenant']->id, $shop['service']->id);
        $viewer = User::factory()->create();
        $role = Role::query()->create(['slug' => 'viewer', 'name' => 'Viewer']);
        $role->permissions()->sync([Permission::query()->where('slug', 'bookings.view')->firstOrFail()->id]);
        Membership::factory()->create(['tenant_id' => $alpha['tenant']->id, 'user_id' => $viewer->id, 'role_id' => $role->id]);
        $this->actingAs($viewer);

        Livewire::test(BookingShow::class, ['booking' => $lost['booking']->id])
            ->assertSee('Paid, but the time was no longer free.')
            ->assertDontSee('Reschedule')
            ->call('reschedule')
            ->assertForbidden();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(BookingStatus::PaymentException, $lost['booking']->fresh()->status);
        $this->assertSame(0, Allocation::query()->where('booking_id', $lost['booking']->id)->count());
    }

    public function test_another_workspace_cannot_open_the_payment_exception(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1);
        $lost = $this->lostSlot($alpha['tenant']->id, $shop['service']->id);
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $this->actingAs($beta['user']);

        Livewire::test(BookingShow::class, ['booking' => $lost['booking']->id])->assertNotFound();

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame(BookingStatus::PaymentException, $lost['booking']->fresh()->status);
    }

    /**
     * @return array{booking: Booking, snapshot: PricingSnapshot, occupant_allocation_id: string}
     */
    private function lostSlot(string $tenantId, string $serviceId): array
    {
        $allocator = app(Allocator::class);
        $start = CarbonImmutable::parse('2026-07-06 14:00:00', 'UTC');
        $held = $allocator->hold($this->intent($tenantId, $serviceId, $start, 'lost', 'Lost Guest'));
        Hold::query()->where('booking_id', $held->id)->update(['expires_at' => now()->subMinute()]);
        $occupant = $allocator->confirm($allocator->hold($this->intent($tenantId, $serviceId, $start, 'occupant', 'Occupant')));
        $lost = $allocator->confirm($held->fresh());
        $this->assertSame(BookingStatus::PaymentException, $lost->status);
        $this->assertSame(0, Allocation::query()->where('booking_id', $lost->id)->count());
        $lost->payment_status = PaymentStatus::Paid;
        $lost->save();
        $snapshot = PricingSnapshot::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $lost->id,
            'currency' => 'usd',
            'rules_version' => 'spec-1.0/7.4-7.5',
            'payload' => [
                'fee_payer' => 'customer_paid',
                'basis_cents' => 10000,
                'fee_cents' => 300,
                'application_fee_cents' => 300,
                'tax_cents' => 0,
                'customer_total_cents' => 10300,
            ],
        ]);
        $payment = Payment::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $lost->id,
            'method' => 'stripe',
            'kind' => 'first_stripe_payment',
            'status' => 'paid',
            'amount_cents' => 10300,
            'fee_cents' => 300,
            'stripe_payment_intent_id' => 'pi_'.$lost->id,
        ]);
        PlatformFee::query()->create([
            'tenant_id' => $tenantId,
            'booking_id' => $lost->id,
            'payment_id' => $payment->id,
            'amount_cents' => 300,
            'refunded_cents' => 0,
            'stripe_fee_id' => 'fee_'.$lost->id,
            'channel' => 'direct',
        ]);

        return [
            'booking' => $lost->fresh(),
            'snapshot' => $snapshot->fresh(),
            'occupant_allocation_id' => Allocation::query()->where('booking_id', $occupant->id)->sole()->id,
        ];
    }

    private function intent(string $tenantId, string $serviceId, CarbonImmutable $start, string $key, string $name): AllocationIntent
    {
        return new AllocationIntent($tenantId, $serviceId, $start, $start->addHour(), 1, $key, $name, $key.'@example.com');
    }
}
