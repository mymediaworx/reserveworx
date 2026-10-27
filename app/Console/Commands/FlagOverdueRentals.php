<?php

namespace App\Console\Commands;

use App\Enums\OperationalStatus;
use App\Models\Booking;
use App\Models\RentalCheckout;
use App\Services\Notifications\BookingEvents;
use App\Support\TenantSweep;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FlagOverdueRentals extends Command
{
    protected $signature = 'rentals:flag-overdue';

    protected $description = 'Queue one overdue hook for each rental still out past its due time, and mark that booking overdue when it is still not arrived, checked in, or in progress. No email is sent. GoHighLevel sends it when that workspace is linked.';

    public function handle(TenantSweep $sweep, BookingEvents $events): int
    {
        $result = $sweep->each('overdue-rentals', function () use ($events): void {
            RentalCheckout::query()
                ->whereNull('returned_at')
                ->where('due_at', '<', Carbon::now('UTC'))
                ->orderBy('id')
                ->each(function (RentalCheckout $checkout) use ($events): void {
                    $this->markOverdue($checkout);
                    $events->overdue($checkout);
                });
        });

        $this->line('sweep_visited='.$result->visited);
        $this->line('sweep_deferred='.($result->deferred ? 'yes' : 'no'));

        return self::SUCCESS;
    }

    private function markOverdue(RentalCheckout $checkout): void
    {
        $booking = Booking::query()->whereKey($checkout->booking_id)->first();

        if (! $booking instanceof Booking) {
            return;
        }

        if (! in_array($booking->operational_status, [
            OperationalStatus::NotArrived,
            OperationalStatus::CheckedIn,
            OperationalStatus::InProgress,
        ], true)) {
            return;
        }

        $booking->operational_status = OperationalStatus::Overdue;
        $booking->save();
    }
}
