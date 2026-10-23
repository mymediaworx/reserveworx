<?php

namespace App\Jobs;

use App\Models\NotificationHook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecordWaitlistPromotion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $tenantId,
        public string $bookingId,
        public string $waitlistEntryId,
    ) {}

    public function handle(): void
    {
        NotificationHook::query()->create([
            'tenant_id' => $this->tenantId,
            'booking_id' => $this->bookingId,
            'kind' => 'waitlist.promoted',
            'payload' => [
                'booking_id' => $this->bookingId,
                'waitlist_entry_id' => $this->waitlistEntryId,
            ],
        ]);
    }
}
