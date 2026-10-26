<?php

namespace Tests\Feature\Notifications;

use App\Models\Booking;
use App\Models\GhlConnection;
use App\Models\NotificationHook;
use App\Models\PricingSnapshot;
use App\Models\TenantNotificationSetting;
use App\Notifications\BookingUpdateNotification;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Notifications\NotificationHooks;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CustomerUpdateEmailTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        config(['mail.default' => 'smtp']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_staff_cancellation_and_its_refund_email_the_customer(): void
    {
        [$alpha, $booking] = $this->confirmedBooking();
        $hooks = app(NotificationHooks::class);
        $hooks->record($alpha['tenant']->id, $booking->id, 'booking.cancelled', 'booking.cancelled:'.$booking->id, ['booking_id' => $booking->id]);
        $hooks->record($alpha['tenant']->id, $booking->id, 'booking.refunded', 'booking.refunded:r1', ['customer_refund_cents' => 3605]);
        $hooks->record($alpha['tenant']->id, $booking->id, 'booking.checked_out', 'booking.checked_out:'.$booking->id, ['booking_id' => $booking->id]);
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();
        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 2);
        Notification::assertSentOnDemand(BookingUpdateNotification::class, function (BookingUpdateNotification $mail, array $channels, AnonymousNotifiable $to): bool {
            return $to->routes['mail'] === 'ada@example.com'
                && $mail->subject === 'Your booking with '.$mail->businessName.' is cancelled'
                && str_contains($mail->lines[0], 'Kayak rental on Sat, Jul 4, 10:00 AM');
        });
        Notification::assertSentOnDemand(BookingUpdateNotification::class, fn (BookingUpdateNotification $mail): bool => str_contains($mail->subject, 'refunded $36.05'));

        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('sent', NotificationHook::query()->where('kind', 'booking.cancelled')->sole()->delivery_status);
        $this->assertSame('sent', NotificationHook::query()->where('kind', 'booking.refunded')->sole()->delivery_status);
        $internal = NotificationHook::query()->where('kind', 'booking.checked_out')->sole();
        $this->assertSame('skipped', $internal->delivery_status);
        $this->assertSame('Not emailed to the customer from this queue.', $internal->delivery_error);
    }

    public function test_switched_off_linked_stale_and_unconfigured_hooks_are_not_emailed(): void
    {
        [$alpha, $booking] = $this->confirmedBooking();
        TenantNotificationSetting::query()->updateOrCreate(
            ['tenant_id' => $alpha['tenant']->id],
            ['staff_alert_recipients' => [], 'transactional_emails' => ['booking.cancelled' => false]],
        );
        $hooks = app(NotificationHooks::class);
        $hooks->record($alpha['tenant']->id, $booking->id, 'booking.cancelled', 'booking.cancelled:'.$booking->id, ['booking_id' => $booking->id]);
        Carbon::setTestNow(Carbon::parse('2026-06-29 15:00:00', 'UTC'));
        $hooks->record($alpha['tenant']->id, $booking->id, 'payment.failed', 'payment.failed:old', ['booking_id' => $booking->id]);
        Carbon::setTestNow(Carbon::parse('2026-07-01 15:00:00', 'UTC'));
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 0);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('The workspace turned this message off.', NotificationHook::query()->where('kind', 'booking.cancelled')->sole()->delivery_error);
        $this->assertSame('Too old to send.', NotificationHook::query()->where('kind', 'payment.failed')->sole()->delivery_error);

        GhlConnection::factory()->create(['tenant_id' => $alpha['tenant']->id, 'location_id' => 'loc-1', 'private_integration_token' => 'pit-test']);
        $hooks->record($alpha['tenant']->id, $booking->id, 'booking.guest_rescheduled', 'booking.guest_rescheduled:x', ['booking_id' => $booking->id]);
        app(TenantManager::class)->forget();
        $this->artisan('integrations:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 0);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('Sent through GoHighLevel.', NotificationHook::query()->where('kind', 'booking.guest_rescheduled')->sole()->delivery_error);

        GhlConnection::query()->delete();
        config(['mail.default' => 'log']);
        $hooks->record($alpha['tenant']->id, $booking->id, 'rental.overdue', 'rental.overdue:x', ['booking_id' => $booking->id, 'due_at' => '2026-07-01T14:00:00Z']);
        app(TenantManager::class)->forget();
        $this->artisan('integrations:dispatch')->assertSuccessful();
        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 0);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('No transactional email provider is configured.', NotificationHook::query()->where('kind', 'rental.overdue')->sole()->delivery_error);
    }

    /**
     * @return array{0: array<string, mixed>, 1: Booking}
     */
    private function confirmedBooking(): array
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant']);
        $allocator = app(Allocator::class);
        $start = CarbonImmutable::parse('2026-07-04 14:00:00', 'UTC');
        $booking = $allocator->confirm($allocator->hold(new AllocationIntent($alpha['tenant']->id, $shop['service']->id, $start, $start->addHour(), 1, 'k1', 'Ada Lovelace', 'ada@example.com')));
        PricingSnapshot::query()->where('booking_id', $booking->id)->delete();
        NotificationHook::query()->delete();

        return [$alpha, $booking];
    }
}
