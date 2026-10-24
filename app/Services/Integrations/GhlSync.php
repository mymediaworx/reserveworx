<?php

namespace App\Services\Integrations;

use App\Enums\BookingStatus;
use App\Jobs\SyncBookingToGoHighLevel;
use App\Models\Booking;
use App\Models\GhlConnection;
use App\Models\Payment;
use App\Tenancy\TenantScope;

class GhlSync
{
    /**
     * @var list<string>
     */
    private const WATCHED = [
        'status',
        'starts_at',
        'ends_at',
        'participants',
        'customer_id',
        'service_id',
        'service_variant_id',
        'location_id',
        'guide_user_id',
        'departure_id',
        'operational_status',
    ];

    public function register(): void
    {
        Booking::created(function (Booking $booking): void {
            $this->queue($booking->tenant_id, $booking->id, 'booking.created');
        });

        Booking::updated(function (Booking $booking): void {
            if ($booking->wasChanged('status') && $booking->status === BookingStatus::Cancelled) {
                $this->queue($booking->tenant_id, $booking->id, 'booking.cancelled');

                return;
            }

            foreach (self::WATCHED as $field) {
                if ($booking->wasChanged($field)) {
                    $this->queue($booking->tenant_id, $booking->id, 'booking.changed');

                    return;
                }
            }
        });

        Payment::created(function (Payment $payment): void {
            if ($payment->status === 'paid') {
                $this->queue($payment->tenant_id, $payment->booking_id, 'payment.succeeded', $payment->id);
            }
        });

        Payment::updated(function (Payment $payment): void {
            if ($payment->wasChanged('status') && $payment->status === 'paid') {
                $this->queue($payment->tenant_id, $payment->booking_id, 'payment.succeeded', $payment->id);
            }
        });
    }

    public function queue(string $tenantId, string $bookingId, string $kind, ?string $paymentId = null): void
    {
        $linked = GhlConnection::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $linked) {
            return;
        }

        SyncBookingToGoHighLevel::dispatch($tenantId, $bookingId, $kind, $paymentId);
    }
}
