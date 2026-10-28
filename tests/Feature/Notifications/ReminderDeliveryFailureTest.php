<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Events\BookingReminderDue;
use App\Listeners\SendBookingReminder;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use RuntimeException;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ReminderDeliveryFailureTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_refused_reminder_is_recorded_once_and_the_booking_stays_confirmed(): void
    {
        $transport = new class implements TransportInterface
        {
            public int $attempts = 0;

            public string $raw = '';

            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                $this->attempts++;
                $this->raw = $message->toString();

                throw new RuntimeException('smtp down');
            }

            public function __toString(): string
            {
                return 'refusing';
            }
        };
        Mail::extend('refusing', function () use ($transport): TransportInterface {
            return $transport;
        });
        config([
            'mail.mailers.refusing.transport' => 'refusing',
            'mail.default' => 'refusing',
        ]);
        Notification::swap(new ChannelManager($this->app));

        $now = CarbonImmutable::parse('2026-06-16 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('pier.test');
        $booking = $this->booking($workspace, $now->addHours(3), 'ada@example.com');
        $foreign = $this->booking($other, $now->addDays(30), 'bea@example.com');
        $counts = $this->moneyCounts();

        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        $this->artisan('bookings:dispatch-reminders')->assertSuccessful();
        app(SendBookingReminder::class)->handle(new BookingReminderDue($workspace['tenant']->id, $booking->id, 1440));

        app(TenantManager::class)->set($workspace['tenant']);
        $hook = NotificationHook::query()->where('kind', 'booking.reminder')->firstOrFail();
        $this->assertSame($booking->id, $hook->booking_id);
        $this->assertSame('failed', $hook->delivery_status);
        $this->assertSame('The mailer refused the message (RuntimeException).', $hook->delivery_error);
        $this->assertNotNull($hook->attempted_at);
        $this->assertSame(1, $transport->attempts);
        $this->assertStringContainsString('ada@example.com', $transport->raw);
        $this->assertStringNotContainsString('bea@example.com', $transport->raw);
        $this->assertStringNotContainsString('smtp down', (string) $hook->delivery_error);
        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentStatus::Unpaid, $booking->payment_status);
        app(TenantManager::class)->forget();

        $foreign->refresh();
        $this->assertSame(BookingStatus::Confirmed, $foreign->status);
        $this->assertSame(0, NotificationHook::withoutGlobalScope(TenantScope::class)->where('booking_id', $foreign->id)->count());
        $this->assertSame($counts, $this->moneyCounts());
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function booking(array $workspace, CarbonImmutable $start, string $email): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'America/New_York',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);

        try {
            $service = Service::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'location_id' => $location->id,
                'name' => 'Hourly boat',
                'kind' => ServiceKind::Rental,
            ]);
            $customerId = Customer::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'name' => 'Guest',
                'email' => $email,
            ])->id;
            $startUtc = $start->utc();

            return Booking::query()->create([
                'tenant_id' => $workspace['tenant']->id,
                'location_id' => $location->id,
                'service_id' => $service->id,
                'customer_id' => $customerId,
                'status' => BookingStatus::Confirmed,
                'payment_status' => PaymentStatus::Unpaid,
                'operational_status' => OperationalStatus::NotArrived,
                'starts_at' => $startUtc,
                'ends_at' => $startUtc->addHour(),
                'participants' => 1,
                'payload_hash' => str_repeat('ab', 32),
            ]);
        } finally {
            app(TenantManager::class)->forget();
        }
    }

    /**
     * @return array<string, int>
     */
    private function moneyCounts(): array
    {
        return [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'prices' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }
}
