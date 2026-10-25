<?php

namespace App\Services\Booking;

use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Payments\StripeNotConfiguredException;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\AuditLogger;
use App\Services\CheckIn\ArrivalCheckIn;
use App\Services\Payments\RefundException;
use App\Services\Payments\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use RuntimeException;

/**
 * Bulk actions for the staff bookings list. Each row uses the same check-in,
 * return, and cancellation services as the booking screen. Cancellation of a
 * card payment uses the operator-cancel refund rule.
 */
final class BookingListActions
{
    public function __construct(
        private readonly ArrivalCheckIn $checkIn,
        private readonly Allocator $allocator,
        private readonly WaitlistPromoter $promoter,
        private readonly RefundService $refunds,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $ids
     * @return array{checked_in: int, already: int, blocked: int, refused: int}
     */
    public function checkIn(array $ids, User $actor): array
    {
        $result = ['checked_in' => 0, 'already' => 0, 'blocked' => 0, 'refused' => 0];

        foreach ($ids as $id) {
            $booking = Booking::query()->find($id);

            if (! $booking instanceof Booking) {
                continue;
            }

            Gate::forUser($actor)->authorize('checkIn', $booking);
            $arrival = $this->checkIn->arrive($booking, $actor);

            if ($arrival->checkedInNow) {
                $result['checked_in']++;
            } elseif ($arrival->already) {
                $result['already']++;
            } elseif ($arrival->blocked) {
                $result['blocked']++;
            } else {
                $result['refused']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $ids
     * @return array{returned: int, not_out: int}
     */
    public function markReturned(array $ids, User $actor): array
    {
        $result = ['returned' => 0, 'not_out' => 0];

        foreach ($ids as $id) {
            $booking = Booking::query()->find($id);

            if (! $booking instanceof Booking) {
                continue;
            }

            Gate::forUser($actor)->authorize('update', $booking);

            try {
                $returned = $this->allocator->returnRental($booking);
            } catch (AllocationUnavailableException) {
                $result['not_out']++;

                continue;
            }

            if ($returned['released_start'] instanceof CarbonImmutable && $returned['released_end'] instanceof CarbonImmutable) {
                $this->promoter->afterRelease($returned['booking'], $returned['released_start'], $returned['released_end']);
            }

            $this->audit->log('booking.returned', $returned['booking'], [], actor: $actor, request: request());
            $result['returned']++;
        }

        return $result;
    }

    /**
     * @param  list<string>  $ids
     * @return array{cancelled: int, refunded: int, problems: list<string>}
     */
    public function cancel(array $ids, User $actor): array
    {
        $result = ['cancelled' => 0, 'refunded' => 0, 'problems' => []];

        foreach ($ids as $id) {
            $booking = Booking::query()->with(['customer', 'service'])->find($id);

            if (! $booking instanceof Booking) {
                continue;
            }

            Gate::forUser($actor)->authorize('update', $booking);

            if ($booking->status === BookingStatus::Cancelled) {
                continue;
            }

            if ($this->hasCardPayment($booking)) {
                try {
                    $this->refunds->issue(
                        $booking,
                        RefundReason::OperatorCancelled,
                        0,
                        0,
                        'list-cancel-'.$booking->id,
                        $actor,
                    );
                    $result['refunded']++;
                } catch (InvalidArgumentException $exception) {
                    if ($exception->getMessage() !== 'Nothing left to refund.') {
                        $this->remember($result['problems'], $this->label($booking).' was not cancelled: '.$exception->getMessage());

                        continue;
                    }
                } catch (RefundException|StripeNotConfiguredException $exception) {
                    $this->remember($result['problems'], $this->label($booking).' was not cancelled: '.$exception->getMessage());

                    continue;
                }
            }

            $cancelled = $this->allocator->cancel($booking);
            $this->promoter->afterRelease($cancelled);
            $this->audit->log('booking.cancelled', $cancelled, [], actor: $actor, request: request());
            $result['cancelled']++;
        }

        return $result;
    }

    /**
     * @param  list<string>  $ids
     */
    public function csv(array $ids, User $actor): string
    {
        $bookings = Booking::query()
            ->with(['service', 'location', 'customer'])
            ->whereIn('id', $ids)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $handle = fopen('php://temp', 'w+');

        if ($handle === false) {
            throw new RuntimeException('Could not build the booking CSV.');
        }

        fputcsv($handle, [
            'booking_id',
            'starts_at',
            'location',
            'item',
            'customer_name',
            'customer_email',
            'party_size',
            'status',
            'payment_status',
            'operational_status',
        ]);

        foreach ($bookings as $booking) {
            Gate::forUser($actor)->authorize('view', $booking);
            $zone = is_string($booking->location?->timezone) && $booking->location->timezone !== ''
                ? $booking->location->timezone
                : 'UTC';

            fputcsv($handle, [
                $booking->id,
                CarbonImmutable::instance($booking->starts_at)->timezone($zone)->format('Y-m-d H:i'),
                $this->cell($booking->location->name ?? ''),
                $this->cell($booking->service->name ?? ''),
                $this->cell($booking->customer->name ?? ''),
                $this->cell($booking->customer->email ?? ''),
                (string) $booking->participants,
                $booking->status->value,
                $booking->payment_status->value,
                $booking->operational_status->value,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    private function hasCardPayment(Booking $booking): bool
    {
        return Payment::query()
            ->where('booking_id', $booking->id)
            ->where('method', 'stripe')
            ->where('status', '!=', 'pending')
            ->where('amount_cents', '>', 0)
            ->exists();
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

    /**
     * @param  list<string>  $problems
     */
    private function remember(array &$problems, string $message): void
    {
        if (count($problems) < 3) {
            $problems[] = $message;
        }
    }

    private function cell(string $value): string
    {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
