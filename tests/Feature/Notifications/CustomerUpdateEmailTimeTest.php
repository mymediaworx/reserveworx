<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Notifications\BookingUpdateNotification;
use App\Services\Notifications\HookDelivery;
use App\Services\Notifications\NotificationHooks;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CustomerUpdateEmailTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_customer_update_emails_name_the_location_zone_including_a_daylight_saving_span(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago', 'email_payment_receipt' => true]);
        $spring = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'spring@example.com', '2026-03-07 15:00:00', '2026-03-09 15:00:00');
        $fall = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'fall@example.com', '2026-10-31 14:00:00', '2026-11-02 14:00:00');
        $july = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'july@example.com', '2026-07-04 14:00:00', '2026-07-04 15:00:00');
        $guest = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'guest@example.com', '2026-07-04 14:00:00', '2026-07-04 15:00:00');
        $receipt = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'receipt@example.com', '2026-07-04 14:00:00', '2026-07-04 15:00:00');
        $utc = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'utc@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'UTC');
        $invalid = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'invalid@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'Not/AZone');
        $overdue = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'overdue@example.com', '2026-03-07 15:00:00', '2026-03-09 15:00:00');
        $earlier = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'earlier@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00');
        $foreign = $this->booking($other['tenant']->id, 'Canoe', 'Bea Creek', 'other@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00');

        $payments = Payment::withoutGlobalScope(TenantScope::class)->count();
        $snapshots = PricingSnapshot::withoutGlobalScope(TenantScope::class)->count();
        $refunds = Refund::withoutGlobalScope(TenantScope::class)->count();
        $fees = PlatformFee::withoutGlobalScope(TenantScope::class)->count();
        $rules = PriceRule::withoutGlobalScope(TenantScope::class)->count();

        $this->useTenant($workspace['tenant']);
        $hooks = app(NotificationHooks::class);
        $hooks->record($workspace['tenant']->id, $spring->id, 'booking.cancelled', 'booking.cancelled:'.$spring->id, ['booking_id' => $spring->id]);
        $hooks->record($workspace['tenant']->id, $guest->id, 'booking.guest_cancelled', 'booking.guest_cancelled:'.$guest->id, ['booking_id' => $guest->id]);
        $hooks->record($workspace['tenant']->id, $fall->id, 'booking.guest_rescheduled', 'booking.guest_rescheduled:'.$fall->id, ['booking_id' => $fall->id]);
        $hooks->record($workspace['tenant']->id, $july->id, 'booking.refunded', 'booking.refunded:'.$july->id, [
            'customer_refund_cents' => 2500,
            'customer_fee_refund_cents' => 0,
        ]);
        $hooks->record($workspace['tenant']->id, $receipt->id, 'payment.completed', 'payment.completed:'.$receipt->id, ['booking_id' => $receipt->id]);
        $hooks->record($workspace['tenant']->id, $utc->id, 'payment.failed', 'payment.failed:'.$utc->id, ['booking_id' => $utc->id]);
        $hooks->record($workspace['tenant']->id, $invalid->id, 'waitlist.promoted', 'waitlist.promoted:'.$invalid->id, ['booking_id' => $invalid->id]);
        $hooks->record($workspace['tenant']->id, $overdue->id, 'rental.overdue', 'rental.overdue:'.$overdue->id, [
            'due_at' => '2026-03-07T15:00:00Z',
        ]);
        $hooks->record($workspace['tenant']->id, $earlier->id, 'rental.overdue', 'rental.overdue:'.$earlier->id, [
            'due_at' => 'not-a-time',
        ]);

        $this->useTenant($other['tenant']);
        app(HookDelivery::class)->deliverQueued();
        Notification::assertNothingSent();

        app(TenantManager::class)->forget();
        $this->artisan('integrations:dispatch')->assertSuccessful();
        $this->artisan('integrations:dispatch')->assertSuccessful();

        $seen = [];
        Notification::assertSentOnDemandTimes(BookingUpdateNotification::class, 9);
        Notification::assertSentOnDemand(
            BookingUpdateNotification::class,
            function (BookingUpdateNotification $notification, array $channels, object $notifiable) use (&$seen): bool {
                $this->assertInstanceOf(AnonymousNotifiable::class, $notifiable);
                $mail = $notification->toMail($notifiable);
                $html = (string) $mail->render();
                $view = $mail->view;
                $this->assertIsArray($view);
                $text = (string) view($view['text'], $mail->viewData)->render();
                $email = $notifiable->routes['mail'] ?? null;
                $this->assertIsString($email);
                $seen[$email] = ['html' => $html, 'text' => $text, 'subject' => $mail->subject];
                $this->assertSame(['mail'], $channels);
                $this->assertStringContainsString('name="viewport"', $html);
                $this->assertStringContainsString('width=device-width', $html);
                $this->assertStringContainsString('overflow-wrap:anywhere', $html);
                $this->assertStringContainsString('max-width:100%', $html);
                $this->assertStringNotContainsString('white-space:nowrap', $html);
                $this->assertStringNotContainsString('<script', $html);

                return true;
            },
        );

        $springWhen = 'Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT';
        $fallWhen = 'Sat, Oct 31, 10:00 AM EDT – Mon, Nov 2, 9:00 AM EST';
        $julyWhen = 'Sat, Jul 4, 10:00 AM – 11:00 AM EDT';
        $utcWhen = 'Tue, Jun 16, 3:00 PM – 4:00 PM UTC';
        $chicagoWhen = 'Tue, Jun 16, 10:00 AM – 11:00 AM CDT';

        $this->assertMail($seen, 'spring@example.com', $springWhen, 'Your booking with Harbor Demo is cancelled', [
            '9:00 AM CST',
            '10:00 AM CDT',
        ]);
        $this->assertMail($seen, 'guest@example.com', $julyWhen, 'You cancelled your booking with Harbor Demo', [
            '9:00 AM CDT',
            '10:00 AM CDT',
        ]);
        $this->assertMail($seen, 'fall@example.com', $fallWhen, 'Your booking with Harbor Demo has a new time', [
            '9:00 AM CDT',
            '8:00 AM CST',
        ]);
        $this->assertMail($seen, 'july@example.com', $julyWhen, 'Harbor Demo refunded $25.00', [
            '9:00 AM CDT',
            '10:00 AM CDT',
        ]);
        $this->assertMail($seen, 'receipt@example.com', $julyWhen, 'Your receipt from Harbor Demo', [
            '9:00 AM CDT',
            '10:00 AM CDT',
        ]);
        $this->assertMail($seen, 'utc@example.com', $utcWhen, 'Your payment to Harbor Demo did not go through', [
            '10:00 AM CDT',
            '11:00 AM EDT',
        ]);
        $this->assertMail($seen, 'invalid@example.com', $chicagoWhen, 'A spot opened with Harbor Demo', [
            '11:00 AM EDT',
            '12:00 PM EDT',
        ]);
        $this->assertMail($seen, 'overdue@example.com', 'at Sat, Mar 7, 10:00 AM EST', 'Your rental from Harbor Demo is past its return time', [
            '11:00 AM EDT',
            '10:00 AM CDT',
        ]);

        foreach (['html', 'text'] as $part) {
            $this->assertStringContainsString('Booking fee refunded: $0.00.', $seen['july@example.com'][$part]);
            $this->assertStringContainsString('Subtotal: $0.00.', $seen['receipt@example.com'][$part]);
            $this->assertStringContainsString('Total: $0.00.', $seen['receipt@example.com'][$part]);
            $this->assertStringContainsString('Paid: $0.00.', $seen['receipt@example.com'][$part]);
            $this->assertStringContainsString('was due back earlier.', $seen['earlier@example.com'][$part]);
            $this->assertStringNotContainsString('Tue, Jun 16, 11:00 AM – 12:00 PM EDT', $seen['earlier@example.com'][$part]);
            $this->assertStringNotContainsString($springWhen, $seen['overdue@example.com'][$part]);
        }

        foreach ($seen as $mail) {
            $this->assertStringNotContainsString('Creek', $mail['html']);
            $this->assertStringNotContainsString('Creek', $mail['text']);
            $this->assertStringNotContainsString('Canoe', $mail['html']);
            $this->assertStringNotContainsString($foreign->id, $mail['html']);
            $this->assertStringNotContainsString('creek.test', $mail['html']);
            $this->assertStringNotContainsString('other@example.com', $mail['html']);
            $this->assertStringNotContainsString('America/Chicago', $mail['html']);
        }

        $this->assertArrayNotHasKey('other@example.com', $seen);

        $this->assertSame($payments, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($snapshots, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($refunds, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($fees, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $storedSpring = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($spring->id);
        $storedFall = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($fall->id);
        $storedUtc = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($utc->id);
        $this->assertSame('2026-03-07 15:00:00', $storedSpring->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $storedSpring->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 14:00:00', $storedFall->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 14:00:00', $storedFall->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-16 15:00:00', $storedUtc->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-16 16:00:00', $storedUtc->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    /**
     * @param  array<string, array{html: string, text: string, subject: string}>  $seen
     * @param  list<string>  $absent
     */
    private function assertMail(array $seen, string $email, string $when, string $subject, array $absent): void
    {
        $this->assertArrayHasKey($email, $seen);
        $this->assertSame($subject, $seen[$email]['subject']);
        foreach (['html', 'text'] as $part) {
            $this->assertStringContainsString($when, $seen[$email][$part]);
            foreach ($absent as $wrong) {
                $this->assertStringNotContainsString($wrong, $seen[$email][$part]);
            }
        }
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    private function booking(string $tenantId, string $serviceName, string $name, string $email, string $startsAt, string $endsAt, string $timezone = 'America/New_York'): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenantId,
            'name' => 'Harbor',
            'timezone' => $timezone,
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => $serviceName,
            'kind' => 'rental',
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenantId,
            'name' => $name,
            'email' => strtolower($email),
        ]);

        return Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'participants' => 2,
            'payload_hash' => hash('sha256', $customer->id.$startsAt.$email),
        ]);
    }
}
