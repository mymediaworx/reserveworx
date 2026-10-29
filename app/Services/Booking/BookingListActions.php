<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\StaffUndoKind;
use App\Models\Booking;
use App\Models\StaffUndoAction;
use App\Models\User;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\AuditLogger;
use App\Services\CheckIn\ArrivalCheckIn;
use App\Services\Staff\StaffUndo;
use App\Support\DisplayZone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Bulk actions for the staff bookings list. Each row uses the same check-in,
 * return, and cancellation services as the booking screen. A cancellation with
 * no refund yet can be undone for 10 seconds. The card refund and the customer
 * notification wait until that window passes.
 */
final class BookingListActions
{
    public function __construct(
        private readonly ArrivalCheckIn $checkIn,
        private readonly Allocator $allocator,
        private readonly WaitlistPromoter $promoter,
        private readonly AuditLogger $audit,
        private readonly BookingCancellation $cancellation,
        private readonly StaffUndo $undo,
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
     * @return array{cancelled: int, refunded: int, problems: list<string>, undo: ?StaffUndoAction}
     */
    public function cancel(array $ids, User $actor): array
    {
        $result = ['cancelled' => 0, 'refunded' => 0, 'problems' => [], 'undo' => null];
        $deferred = [];

        foreach ($ids as $id) {
            $booking = Booking::query()->with(['customer', 'service'])->find($id);

            if (! $booking instanceof Booking) {
                continue;
            }

            Gate::forUser($actor)->authorize('update', $booking);

            if ($booking->status === BookingStatus::Cancelled || $this->undo->coversBooking($booking->id)) {
                continue;
            }

            if ($this->cancellation->refundIssued($booking)) {
                $finished = $this->cancellation->finalize($booking, $actor, true);

                if ($finished['problem'] !== null) {
                    $this->remember($result['problems'], $finished['problem']);

                    continue;
                }

                if ($finished['cancelled']) {
                    $result['cancelled']++;
                }

                if ($finished['refunded']) {
                    $result['refunded']++;
                }

                continue;
            }

            $blocked = $this->cancellation->blockedMessage($booking);

            if ($blocked !== null) {
                $this->remember($result['problems'], $blocked);

                continue;
            }

            $deferred[] = [
                'id' => $booking->id,
                'refund_card' => $this->cancellation->hasCardPayment($booking),
                'location_id' => $booking->location_id,
            ];
        }

        if ($deferred !== []) {
            $count = count($deferred);
            $result['undo'] = $this->undo->offer(
                $actor,
                StaffUndoKind::BookingCancel,
                $count === 1 ? 'Booking cancelled.' : 'Cancelled '.$count.'.',
                $this->sharedLocation($deferred),
                null,
                ['bookings' => array_map(
                    fn (array $row): array => ['id' => $row['id'], 'refund_card' => $row['refund_card']],
                    $deferred,
                )],
            );
            $result['cancelled'] += $count;
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

            fputcsv($handle, [
                $booking->id,
                $this->startClock($booking),
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

    /**
     * Location-local start, with that instant's abbreviation, so the two
     * 1:30 AM hours on a fall-back morning stay different cells.
     */
    private function startClock(Booking $booking): string
    {
        $local = CarbonImmutable::instance($booking->starts_at)->timezone(DisplayZone::forLocation($booking->location));

        return $local->format('Y-m-d H:i').' '.$local->format('T');
    }

    /**
     * @param  list<array{id: string, refund_card: bool, location_id: ?string}>  $rows
     */
    private function sharedLocation(array $rows): ?string
    {
        $locations = [];

        foreach ($rows as $row) {
            if (is_string($row['location_id']) && $row['location_id'] !== '') {
                $locations[$row['location_id']] = true;
            }
        }

        $ids = array_keys($locations);

        return count($ids) === 1 ? $ids[0] : null;
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
