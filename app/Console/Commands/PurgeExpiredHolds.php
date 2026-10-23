<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\Hold;
use App\Services\Booking\WaitlistPromoter;
use App\Tenancy\TenantScope;
use Illuminate\Console\Command;

class PurgeExpiredHolds extends Command
{
    protected $signature = 'holds:purge-expired';

    protected $description = 'Delete holds whose expiry has passed, then promote the oldest eligible waitlist entry.';

    public function handle(WaitlistPromoter $promoter): int
    {
        $holds = Hold::withoutGlobalScope(TenantScope::class)
            ->where('expires_at', '<=', now())
            ->get(['id', 'booking_id']);

        $bookingIds = $holds->pluck('booking_id')->unique()->filter()->values();

        Hold::withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $holds->pluck('id'))
            ->delete();

        $bookings = Booking::withoutGlobalScope(TenantScope::class)
            ->whereIn('id', $bookingIds)
            ->get();

        foreach ($bookings as $booking) {
            $promoter->afterRelease($booking);
        }

        $this->line('holds_purged='.$holds->count());

        return self::SUCCESS;
    }
}
