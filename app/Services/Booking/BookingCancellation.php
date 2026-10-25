<?php

namespace App\Services\Booking;

use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Payments\StripeGateway;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Services\Allocation\Allocator;
use App\Services\AuditLogger;
use App\Services\Payments\RefundException;
use App\Services\Payments\RefundService;
use App\Services\Payments\UnconfiguredStripeGateway;
use InvalidArgumentException;

/**
 * Staff cancellation of one booking. A card payment is refunded with the
 * operator-cancel rule before the booking is cancelled. The caller decides
 * whether that refund runs now or after the undo window.
 */
final class BookingCancellation
{
    public function __construct(
        private readonly Allocator $allocator,
        private readonly WaitlistPromoter $promoter,
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
        private readonly StripeGateway $stripe,
    ) {}

    public function refundIssued(Booking $booking): bool
    {
        return Refund::query()->where('booking_id', $booking->id)->exists();
    }

    public function hasCardPayment(Booking $booking): bool
    {
        return Payment::query()
            ->where('booking_id', $booking->id)
            ->where('method', 'stripe')
            ->where('status', '!=', 'pending')
            ->where('amount_cents', '>', 0)
            ->exists();
    }

    /**
     * A card refund cannot start while Stripe is missing. Returning the staff
     * message leaves the booking unchanged instead of offering an undo that
     * would fail when the window ends.
     */
    public function blockedMessage(Booking $booking): ?string
    {
        if (! $this->hasCardPayment($booking) || ! $this->stripe instanceof UnconfiguredStripeGateway) {
            return null;
        }

        return $this->label($booking).' was not cancelled: '.(new StripeNotConfiguredException)->getMessage();
    }

    /**
     * @return array{cancelled: bool, refunded: bool, problem: ?string}
     */
    public function finalize(Booking $booking, User $actor, bool $refundCard): array
    {
        $booking = $booking->fresh() ?? $booking;

        if ($booking->status === BookingStatus::Cancelled) {
            return ['cancelled' => false, 'refunded' => false, 'problem' => null];
        }

        $refunded = false;

        if ($refundCard && $this->hasCardPayment($booking)) {
            try {
                $this->refunds->issue(
                    $booking,
                    RefundReason::OperatorCancelled,
                    0,
                    0,
                    'list-cancel-'.$booking->id,
                    $actor,
                );
                $refunded = true;
            } catch (InvalidArgumentException $exception) {
                if ($exception->getMessage() !== 'Nothing left to refund.') {
                    return ['cancelled' => false, 'refunded' => false, 'problem' => $this->label($booking).' was not cancelled: '.$exception->getMessage()];
                }
            } catch (RefundException|StripeNotConfiguredException $exception) {
                return ['cancelled' => false, 'refunded' => false, 'problem' => $this->label($booking).' was not cancelled: '.$exception->getMessage()];
            }
        }

        $cancelled = $this->allocator->cancel($booking);
        $this->promoter->afterRelease($cancelled);
        $this->audit->log('booking.cancelled', $cancelled, [], actor: $actor, request: request());

        return ['cancelled' => true, 'refunded' => $refunded, 'problem' => null];
    }

    private function label(Booking $booking): string
    {
        $name = $booking->customer?->name;

        if (is_string($name) && $name !== '') {
            return $name;
        }

        $service = $booking->service?->name;

        return is_string($service) && $service !== '' ? $service : 'A booking';
    }
}
