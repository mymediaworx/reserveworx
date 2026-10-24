<?php

namespace App\Services\CheckIn;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Models\Booking;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marks a confirmed arrival checked in. Repeating it does not write a second audit row.
 * A missing current waiver blocks the change unless staff pass a reason.
 */
final class ArrivalCheckIn
{
    public function __construct(
        private readonly WaiverGate $waivers,
        private readonly BalanceDue $balance,
        private readonly AuditLogger $audit,
    ) {}

    public function arrive(Booking $booking, User $actor, ?string $overrideReason = null): CheckInArrival
    {
        return DB::transaction(function () use ($booking, $actor, $overrideReason): CheckInArrival {
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $waivers = $this->waivers->status($locked);
            $due = $this->balance->forBooking($locked);
            $reason = $this->reason($overrideReason);

            if ($locked->status !== BookingStatus::Confirmed) {
                return $this->result($locked, false, false, false, true, $waivers, $due);
            }

            if ($this->alreadyRecorded($locked->operational_status)) {
                return $this->result($locked, false, true, false, false, $waivers, $due);
            }

            if (! $waivers->complete && $reason === null) {
                return $this->result($locked, false, false, true, false, $waivers, $due);
            }

            $locked->operational_status = OperationalStatus::CheckedIn;
            $locked->checked_in_at = Carbon::now('UTC');
            $locked->checked_in_by = $actor->id;
            $locked->check_in_waiver_override_reason = $waivers->complete ? null : $reason;
            $locked->save();

            $properties = [
                'operational_status' => OperationalStatus::CheckedIn->value,
                'waiver_override' => ! $waivers->complete,
            ];

            if (! $waivers->complete) {
                $properties['reason'] = $reason;
            }

            $this->audit->log('booking.checked_in', $locked, $properties, request: request());

            $fresh = $locked->fresh() ?? $locked;

            return $this->result($fresh, true, false, false, false, $waivers, $due);
        });
    }

    /**
     * @param  array{cents: int|null, currency: string}  $due
     */
    private function result(
        Booking $booking,
        bool $checkedInNow,
        bool $already,
        bool $blocked,
        bool $refused,
        WaiverReadiness $waivers,
        array $due,
    ): CheckInArrival {
        return new CheckInArrival(
            $booking,
            $checkedInNow,
            $already,
            $blocked,
            $refused,
            $waivers,
            $due['cents'],
            $due['currency'],
        );
    }

    private function alreadyRecorded(OperationalStatus $status): bool
    {
        return in_array($status, [
            OperationalStatus::CheckedIn,
            OperationalStatus::InProgress,
            OperationalStatus::Returned,
            OperationalStatus::Completed,
        ], true);
    }

    private function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $reason = trim($reason);
        $length = mb_strlen($reason);

        if ($length < 3 || $length > 500) {
            return null;
        }

        return $reason;
    }
}
