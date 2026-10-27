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
use App\Models\TenantSetting;
use App\Notifications\BookingReminderNotification;
use App\Support\BookingReference;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ReminderVisitTimeTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_reminder_email_names_the_location_zone_including_a_daylight_saving_span(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', 'UTC'));
        Notification::fake();
        config(['mail.default' => 'smtp']);
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update([
                'timezone' => 'America/Chicago',
                'reminder_offsets_minutes' => json_encode([60]),
            ]);
        $spring = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'spring@example.com', '2026-03-07 15:00:00', '2026-03-09 15:00:00');
        $fall = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'fall@example.com', '2026-10-31 14:00:00', '2026-11-02 14:00:00');
        $utc = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'utc@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'UTC');
        $invalid = $this->booking($workspace['tenant']->id, 'Kayak', 'Ada Kayak', 'invalid@example.com', '2026-06-16 15:00:00', '2026-06-16 16:00:00', 'Not/AZone');
        $foreign = $this->booking($other['tenant']->id, 'Canoe', 'Bea Creek', 'other@example.com', '2026-01-02 15:00:00', '2026-01-02 16:00:00');

        $payments = Payment::withoutGlobalScope(TenantScope::class)->count();
        $snapshots = PricingSnapshot::withoutGlobalScope(TenantScope::class)->count();
        $refunds = Refund::withoutGlobalScope(TenantScope::class)->count();
        $fees = PlatformFee::withoutGlobalScope(TenantScope::class)->count();
        $rules = PriceRule::withoutGlobalScope(TenantScope::class)->count();

        $this->dispatch($spring);
        $this->dispatch($spring);
        Notification::assertSentOnDemandTimes(BookingReminderNotification::class, 1);
        $this->dispatch($fall);
        $this->dispatch($utc);
        $this->dispatch($invalid);

        $seen = [];
        Notification::assertSentOnDemandTimes(BookingReminderNotification::class, 4);
        Notification::assertSentOnDemand(
            BookingReminderNotification::class,
            function (BookingReminderNotification $notification, array $channels, object $notifiable) use (&$seen): bool {
                $this->assertInstanceOf(AnonymousNotifiable::class, $notifiable);
                $mail = $notification->toMail($notifiable);
                $html = (string) $mail->render();
                $view = $mail->view;
                $this->assertIsArray($view);
                $text = (string) view($view['text'], $mail->viewData)->render();
                $email = $notifiable->routes['mail'] ?? null;
                $this->assertIsString($email);
                $seen[$email] = ['html' => $html, 'text' => $text];
                $this->assertSame(['mail'], $channels);
                $this->assertSame('Booking reminder', $mail->subject);
                $this->assertStringContainsString($notification->when, $html);
                $this->assertStringContainsString($notification->when, $text);
                $this->assertStringContainsString('Booking reference '.$notification->reference, $html);
                $this->assertStringContainsString('Booking reference '.$notification->reference, $text);
                $this->assertStringContainsString('This reminder is 60 minutes before the start.', $html);
                $this->assertStringContainsString('This reminder is 60 minutes before the start.', $text);
                $this->assertStringContainsString('name="viewport"', $html);
                $this->assertStringContainsString('width=device-width', $html);
                $this->assertStringContainsString('overflow-wrap:anywhere', $html);
                $this->assertStringContainsString('max-width:100%', $html);
                $this->assertStringNotContainsString('white-space:nowrap', $html);
                $this->assertStringNotContainsString('<script', $html);

                return true;
            },
        );

        $this->assertMail($seen, 'spring@example.com', $spring, 'Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT', [
            '9:00 AM CST',
            '10:00 AM CDT',
        ]);
        $this->assertMail($seen, 'fall@example.com', $fall, 'Sat, Oct 31, 10:00 AM EDT – Mon, Nov 2, 9:00 AM EST', [
            '9:00 AM CDT',
            '8:00 AM CST',
        ]);
        $this->assertMail($seen, 'utc@example.com', $utc, 'Tue, Jun 16, 3:00 PM – 4:00 PM UTC', [
            '10:00 AM CDT',
            '11:00 AM EDT',
        ]);
        $this->assertMail($seen, 'invalid@example.com', $invalid, 'Tue, Jun 16, 10:00 AM – 11:00 AM CDT', [
            '11:00 AM EDT',
            '12:00 PM EDT',
        ]);

        foreach ($seen as $mail) {
            $this->assertStringNotContainsString('Creek', $mail['html']);
            $this->assertStringNotContainsString('Creek', $mail['text']);
            $this->assertStringNotContainsString('Canoe', $mail['html']);
            $this->assertStringNotContainsString($foreign->id, $mail['html']);
            $this->assertStringNotContainsString('creek.test', $mail['html']);
            $this->assertStringNotContainsString('America/Chicago', $mail['html']);
            $this->assertStringNotContainsString($spring->id, $mail['html']);
        }

        $this->assertArrayNotHasKey('other@example.com', $seen);

        $this->assertSame($payments, Payment::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($snapshots, PricingSnapshot::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($refunds, Refund::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($fees, PlatformFee::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame($rules, PriceRule::withoutGlobalScope(TenantScope::class)->count());
        $storedSpring = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($spring->id);
        $storedFall = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($fall->id);
        $this->assertSame('2026-03-07 15:00:00', $storedSpring->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 15:00:00', $storedSpring->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-31 14:00:00', $storedFall->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 14:00:00', $storedFall->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    /**
     * @param  array<string, array{html: string, text: string}>  $seen
     * @param  list<string>  $absent
     */
    private function assertMail(array $seen, string $email, Booking $booking, string $when, array $absent): void
    {
        $this->assertArrayHasKey($email, $seen);
        $reference = BookingReference::short($booking->id);
        foreach (['html', 'text'] as $part) {
            $this->assertStringContainsString($when, $seen[$email][$part]);
            $this->assertStringContainsString($reference, $seen[$email][$part]);
            $this->assertStringContainsString('Your booking for Kayak is coming up.', $seen[$email][$part]);
            foreach ($absent as $wrong) {
                $this->assertStringNotContainsString($wrong, $seen[$email][$part]);
            }
        }
    }

    private function dispatch(Booking $booking): void
    {
        Carbon::setTestNow(Carbon::parse($booking->starts_at)->utc()->subMinutes(30));
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
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
