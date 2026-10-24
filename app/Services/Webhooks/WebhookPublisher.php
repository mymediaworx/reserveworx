<?php

namespace App\Services\Webhooks;

use App\Enums\WebhookEvent;
use App\Jobs\DeliverWebhook;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Queues one delivery per active endpoint that subscribed to the event.
 * Nothing here calls GoHighLevel.
 */
final class WebhookPublisher
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function bookingCreated(Booking $booking): void
    {
        $this->publish(
            $booking->tenant_id,
            WebhookEvent::BookingCreated->value,
            $this->bookingData($booking),
            'booking.created:'.$booking->id,
        );
    }

    public function bookingCancelled(Booking $booking): void
    {
        $this->publish(
            $booking->tenant_id,
            WebhookEvent::BookingCancelled->value,
            $this->bookingData($booking),
            'booking.cancelled:'.$booking->id,
        );
    }

    public function bookingChanged(Booking $booking): void
    {
        $this->publish(
            $booking->tenant_id,
            WebhookEvent::BookingChanged->value,
            $this->bookingData($booking),
            'booking.changed:'.$booking->id.':'.Str::lower((string) Str::ulid()),
        );
    }

    public function paymentSucceeded(Payment $payment): void
    {
        if ($payment->status !== 'paid') {
            return;
        }

        $this->publish(
            $payment->tenant_id,
            WebhookEvent::PaymentSucceeded->value,
            [
                'payment_id' => $payment->id,
                'booking_id' => $payment->booking_id,
                'amount_cents' => $payment->amount_cents,
                'method' => (string) $payment->method,
                'status' => (string) $payment->status,
            ],
            'payment.succeeded:'.$payment->id,
        );
    }

    public function refundCreated(Refund $refund): void
    {
        $this->publish(
            $refund->tenant_id,
            WebhookEvent::RefundCreated->value,
            [
                'refund_id' => $refund->id,
                'booking_id' => $refund->booking_id,
                'payment_id' => $refund->payment_id,
                'reason' => $refund->reason,
                'customer_refund_cents' => $refund->customer_refund_cents,
            ],
            'refund.created:'.$refund->id,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function publish(string $tenantId, string $event, array $data, ?string $dedupeKey): void
    {
        if (! in_array($event, WebhookEvent::values(), true)) {
            throw new InvalidArgumentException('Unknown webhook event.');
        }

        $context = $this->tenants->id();

        if ($context === null) {
            return;
        }

        if ($context !== $tenantId) {
            throw new TenantContextException('Refusing to queue a webhook for a different workspace.');
        }

        $endpoints = WebhookEndpoint::query()
            ->where('active', true)
            ->whereJsonContains('events', $event)
            ->orderBy('id')
            ->get();

        foreach ($endpoints as $endpoint) {
            if ($endpoint->tenant_id !== $tenantId) {
                throw new TenantContextException('Refusing to queue a webhook for a different workspace.');
            }

            $this->queue($endpoint, $tenantId, $event, $data, $dedupeKey);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function queue(WebhookEndpoint $endpoint, string $tenantId, string $event, array $data, ?string $dedupeKey): void
    {
        try {
            $delivery = WebhookDelivery::query()->create([
                'tenant_id' => $tenantId,
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event,
                'dedupe_key' => $dedupeKey,
                'payload' => $data,
                'status' => 'pending',
                'attempt_count' => 0,
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }

            return;
        }

        DeliverWebhook::dispatch($tenantId, $delivery->id);
    }

    /**
     * @return array<string, int|string>
     */
    private function bookingData(Booking $booking): array
    {
        return [
            'booking_id' => $booking->id,
            'status' => $booking->status->value,
            'payment_status' => $booking->payment_status->value,
            'starts_at' => $booking->starts_at->clone()->utc()->toIso8601String(),
            'ends_at' => $booking->ends_at->clone()->utc()->toIso8601String(),
            'participants' => $booking->participants,
        ];
    }
}
