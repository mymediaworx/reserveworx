<?php

namespace Tests\Feature\Notifications;

use App\Models\Booking;
use App\Models\GhlConnection;
use App\Models\LocationTaxRule;
use App\Models\NotificationHook;
use App\Models\OperatorFee;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\TenantNotificationSetting;
use App\Notifications\BookingUpdateNotification;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Notifications\NotificationHooks;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
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
        Notification::assertSentOnDemand(BookingUpdateNotification::class, function (BookingUpdateNotification $mail): bool {
            return str_contains($mail->subject, 'refunded $36.05')
                && ! str_contains(implode("\n", $mail->lines), 'Booking fee');
        });

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

    public function test_a_refund_email_lists_the_stored_booking_fee_and_leaves_money_rows_unchanged(): void
    {
        [$ada, $adaBooking] = $this->confirmedBooking();
        $bea = $this->workspace('beta.test');
        app(TenantManager::class)->set($bea['tenant']);
        $beaShop = $this->rentalShop($bea['tenant']);
        $allocator = app(Allocator::class);
        $start = CarbonImmutable::parse('2026-07-05 15:00:00', 'UTC');
        $beaBooking = $allocator->confirm($allocator->hold(new AllocationIntent(
            $bea['tenant']->id,
            $beaShop['service']->id,
            $start,
            $start->addHour(),
            1,
            'k2',
            'Bea Lovelace',
            'bea@example.com',
        )));
        PricingSnapshot::query()->where('booking_id', $beaBooking->id)->delete();
        NotificationHook::query()->delete();

        app(TenantManager::class)->set($ada['tenant']);
        $adaSnapshot = PricingSnapshot::query()->create([
            'tenant_id' => $ada['tenant']->id,
            'booking_id' => $adaBooking->id,
            'currency' => 'USD',
            'rules_version' => 'v1',
            'payload' => ['customer_fee_line_cents' => 300],
        ]);
        app(NotificationHooks::class)->record($ada['tenant']->id, $adaBooking->id, 'booking.refunded', 'booking.refunded:ada', [
            'customer_refund_cents' => 3605,
            'customer_fee_refund_cents' => 108,
        ]);

        app(TenantManager::class)->set($bea['tenant']);
        $beaSnapshot = PricingSnapshot::query()->create([
            'tenant_id' => $bea['tenant']->id,
            'booking_id' => $beaBooking->id,
            'currency' => 'USD',
            'rules_version' => 'v1',
            'payload' => ['customer_fee_line_cents' => 200],
        ]);
        app(NotificationHooks::class)->record($bea['tenant']->id, $beaBooking->id, 'booking.refunded', 'booking.refunded:bea', [
            'customer_refund_cents' => 5000,
            'customer_fee_refund_cents' => 0,
        ]);

        $adaReference = strtoupper(substr($adaBooking->id, -8));
        $beaReference = strtoupper(substr($beaBooking->id, -8));
        $adaPayload = $adaSnapshot->payload;
        $beaPayload = $beaSnapshot->payload;
        $before = $this->moneyRowCounts();
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();
        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 2);
        Notification::assertSentOnDemand(
            BookingUpdateNotification::class,
            function (BookingUpdateNotification $mail, array $channels, AnonymousNotifiable $to) use ($adaReference, $beaReference): bool {
                if (($to->routes['mail'] ?? null) !== 'ada@example.com') {
                    return false;
                }

                $text = implode("\n", $mail->lines);
                $html = (string) $mail->toMail($to)->render();
                $this->assertSame(['mail'], $channels);
                $this->assertStringContainsString('refunded $36.05', $mail->subject);
                $this->assertStringContainsString('refunded $36.05', $text);
                $this->assertStringContainsString('Booking fee refunded: $1.08.', $text);
                $this->assertStringContainsString('Reference: '.$adaReference.'.', $text);
                $this->assertStringNotContainsString('Booking fee refunded: $3.00.', $text);
                $this->assertStringNotContainsString($beaReference, $text);
                $this->assertStringNotContainsString('bea@example.com', $text);
                $this->assertStringNotContainsString('credit card fee', strtolower($text));
                $this->assertStringNotContainsString('surcharge', strtolower($text));
                $this->assertStringNotContainsString('processing fee', strtolower($text));
                $this->assertStringContainsString('name="viewport"', $html);
                $this->assertStringContainsString('width=device-width', $html);
                $this->assertStringContainsString('overflow-wrap:anywhere', $html);
                $this->assertStringContainsString('max-width:100%', $html);
                $this->assertStringNotContainsString('white-space:nowrap', $html);
                $this->assertStringNotContainsString('<script', $html);

                return true;
            },
        );
        Notification::assertSentOnDemand(
            BookingUpdateNotification::class,
            function (BookingUpdateNotification $mail, array $channels, AnonymousNotifiable $to) use ($adaReference, $beaReference): bool {
                if (($to->routes['mail'] ?? null) !== 'bea@example.com') {
                    return false;
                }

                $text = implode("\n", $mail->lines);

                return $channels === ['mail']
                    && str_contains($mail->subject, 'refunded $50.00')
                    && str_contains($text, 'Booking fee refunded: $0.00.')
                    && str_contains($text, 'Reference: '.$beaReference.'.')
                    && ! str_contains($text, 'Booking fee refunded: $2.00.')
                    && ! str_contains($text, $adaReference)
                    && ! str_contains(strtolower($text), 'credit card fee')
                    && ! str_contains(strtolower($text), 'surcharge')
                    && ! str_contains(strtolower($text), 'processing fee');
            },
        );

        $this->assertSame($before, $this->moneyRowCounts());
        $this->assertSame($adaPayload, PricingSnapshot::withoutGlobalScope(TenantScope::class)->whereKey($adaSnapshot->id)->sole()->payload);
        $this->assertSame($beaPayload, PricingSnapshot::withoutGlobalScope(TenantScope::class)->whereKey($beaSnapshot->id)->sole()->payload);
    }

    public function test_a_turned_off_refund_notice_is_skipped_and_writes_no_refund(): void
    {
        [$alpha, $booking] = $this->confirmedBooking();
        TenantNotificationSetting::query()->updateOrCreate(
            ['tenant_id' => $alpha['tenant']->id],
            ['staff_alert_recipients' => [], 'transactional_emails' => ['refund.issued' => false]],
        );
        app(NotificationHooks::class)->record($alpha['tenant']->id, $booking->id, 'booking.refunded', 'booking.refunded:off', [
            'customer_refund_cents' => 3605,
            'customer_fee_refund_cents' => 108,
        ]);
        $before = $this->moneyRowCounts();
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 0);
        app(TenantManager::class)->set($alpha['tenant']);
        $hook = NotificationHook::query()->where('kind', 'booking.refunded')->sole();
        $this->assertSame('skipped', $hook->delivery_status);
        $this->assertSame('The workspace turned this message off.', $hook->delivery_error);

        TenantNotificationSetting::query()->update(['transactional_emails' => ['refund.issued' => true]]);
        GhlConnection::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => 'loc-refund',
            'private_integration_token' => 'pit-test',
        ]);
        app(NotificationHooks::class)->record($alpha['tenant']->id, $booking->id, 'booking.refunded', 'booking.refunded:linked', [
            'customer_refund_cents' => 3605,
            'customer_fee_refund_cents' => 108,
        ]);
        app(TenantManager::class)->forget();
        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 0);
        app(TenantManager::class)->set($alpha['tenant']);
        $this->assertSame('Sent through GoHighLevel.', NotificationHook::query()->where('dedupe_key', 'booking.refunded:linked')->sole()->delivery_error);
        $this->assertSame($before, $this->moneyRowCounts());
    }

    public function test_a_refund_email_omits_a_missing_negative_or_non_numeric_fee_portion(): void
    {
        [$alpha, $booking] = $this->confirmedBooking();
        $hooks = app(NotificationHooks::class);
        $hooks->record($alpha['tenant']->id, $booking->id, 'booking.refunded', 'booking.refunded:missing', [
            'customer_refund_cents' => 1000,
        ]);
        $hooks->record($alpha['tenant']->id, $booking->id, 'booking.refunded', 'booking.refunded:negative', [
            'customer_refund_cents' => 2000,
            'customer_fee_refund_cents' => -1,
        ]);
        $hooks->record($alpha['tenant']->id, $booking->id, 'booking.refunded', 'booking.refunded:text', [
            'customer_refund_cents' => 3000,
            'customer_fee_refund_cents' => 'nope',
        ]);
        app(TenantManager::class)->forget();

        $this->artisan('integrations:dispatch')->assertSuccessful();

        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 3);
        foreach (['refunded $10.00', 'refunded $20.00', 'refunded $30.00'] as $subject) {
            Notification::assertSentOnDemand(
                BookingUpdateNotification::class,
                function (BookingUpdateNotification $mail) use ($subject): bool {
                    if (! str_contains($mail->subject, $subject)) {
                        return false;
                    }

                    $this->assertStringNotContainsString('Booking fee', implode("\n", $mail->lines));

                    return true;
                },
            );
        }
    }

    /**
     * @return array<string, int>
     */
    private function moneyRowCounts(): array
    {
        return [
            'price_rules' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
            'operator_fees' => OperatorFee::withoutGlobalScope(TenantScope::class)->count(),
            'platform_fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'platform_fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'snapshots' => PricingSnapshot::withoutGlobalScope(TenantScope::class)->count(),
        ];
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
