<?php

namespace App\Services\Integrations;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
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
            $this->queueBookingUpdate($booking);
        });

        Payment::created(function (Payment $payment): void {
            $this->queuePayment($payment, true);
        });

        Payment::updated(function (Payment $payment): void {
            $this->queuePayment($payment, false);
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

    private function queueBookingUpdate(Booking $booking): void
    {
        if ($booking->wasChanged('status') && $booking->status === BookingStatus::Cancelled) {
            $this->queue($booking->tenant_id, $booking->id, 'booking.cancelled');

            return;
        }

        $covered = [];

        if ($booking->wasChanged('status') && $booking->status === BookingStatus::Confirmed) {
            $this->queue($booking->tenant_id, $booking->id, 'booking.confirmed');
            $covered[] = 'status';
        }

        if ($booking->wasChanged('operational_status')) {
            $kind = $this->operationalKind($booking->operational_status);

            if ($kind !== null) {
                $this->queue($booking->tenant_id, $booking->id, $kind);
                $covered[] = 'operational_status';
            }
        }

        foreach (self::WATCHED as $field) {
            if (in_array($field, $covered, true)) {
                continue;
            }

            if ($booking->wasChanged($field)) {
                $this->queue($booking->tenant_id, $booking->id, 'booking.changed');

                return;
            }
        }
    }

    private function operationalKind(OperationalStatus $status): ?string
    {
        return match ($status) {
            OperationalStatus::CheckedIn => 'booking.checked_in',
            OperationalStatus::Returned => 'booking.returned',
            OperationalStatus::Overdue => 'booking.overdue',
            OperationalStatus::Completed => 'booking.completed',
            OperationalStatus::NotArrived, OperationalStatus::InProgress => null,
        };
    }

    private function queuePayment(Payment $payment, bool $created): void
    {
        if (! $created && ! $payment->wasChanged('status')) {
            return;
        }

        if ($payment->status === 'paid') {
            $this->queue($payment->tenant_id, $payment->booking_id, 'payment.succeeded', $payment->id);

            return;
        }

        if ($payment->status === 'failed') {
            $this->queue($payment->tenant_id, $payment->booking_id, 'payment.failed', $payment->id);
        }
    }
}
