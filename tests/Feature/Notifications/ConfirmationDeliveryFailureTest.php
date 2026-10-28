<?php

namespace Tests\Feature\Notifications;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\NotificationHook;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Notifications\BookingConfirmationMail;
use App\Services\Notifications\BookingEvents;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
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

class ConfirmationDeliveryFailureTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_a_refused_confirmation_is_recorded_once_and_the_booking_stays_confirmed(): void
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

        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('creek.test');
        $booking = $this->booking($workspace, 'Ada Kayak', 'ada@example.com');
        $foreign = $this->booking($other, 'Bea Creek', 'bea@example.com');
        $counts = $this->moneyCounts();

        $this->useTenant($workspace['tenant']);
        app(BookingEvents::class)->confirmed($booking->fresh() ?? $booking);
        app(BookingEvents::class)->confirmed($booking->fresh() ?? $booking);
        $this->useTenant($other['tenant']);
        app(BookingConfirmationMail::class)->send($booking->fresh() ?? $booking);

        $this->useTenant($workspace['tenant']);
        $hook = NotificationHook::query()->where('dedupe_key', 'booking.confirmed:'.$booking->id)->sole();
        $this->assertSame('failed', $hook->delivery_status);
        $this->assertSame('The mailer refused the message (RuntimeException).', $hook->delivery_error);
        $this->assertNotNull($hook->attempted_at);
        $this->assertSame(1, $transport->attempts);
        $this->assertStringContainsString('ada@example.com', $transport->raw);
        $this->assertStringNotContainsString('bea@example.com', $transport->raw);
        $this->assertStringNotContainsString('smtp down', $hook->delivery_error);
        $booking->refresh();
        $this->assertSame(BookingStatus::Confirmed, $booking->status);
        $this->assertSame(PaymentStatus::Unpaid, $booking->payment_status);
        $foreign->refresh();
        $this->assertSame(BookingStatus::Confirmed, $foreign->status);
        $this->assertSame(0, NotificationHook::withoutGlobalScope(TenantScope::class)->where('booking_id', $foreign->id)->count());
        $this->assertSame($counts, $this->moneyCounts());
    }

    /**
     * @param  array{tenant: Tenant}  $workspace
     */
    private function booking(array $workspace, string $customerName, string $email): Booking
    {
        $this->useTenant($workspace['tenant']);
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $customerName.' dock',
            'timezone' => 'America/New_York',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Kayak',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => $customerName,
            'email' => $email,
        ]);
        $start = CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC');

        return Booking::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'participants' => 1,
            'payload_hash' => str_repeat('ab', 32),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function moneyCounts(): array
    {
        return [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'platform_fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'platform_fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'snapshots' => PricingSnapshot::withoutGlobalScope(TenantScope::class)->count(),
            'price_rules' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'location_taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
            'audits' => AuditLog::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }
}
