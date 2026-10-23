<?php

namespace App\Jobs;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Notifications\NotificationHooks;
use App\Tenancy\TenantManager;
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

    public function handle(TenantManager $tenants, NotificationHooks $hooks): void
    {
        $previous = $tenants->get();
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant || $tenant->status === TenantStatus::Terminated) {
            return;
        }

        $tenants->set($tenant);

        try {
            $hooks->record(
                $this->tenantId,
                $this->bookingId,
                'waitlist.promoted',
                'waitlist.promoted:'.$this->waitlistEntryId,
                [
                    'booking_id' => $this->bookingId,
                    'waitlist_entry_id' => $this->waitlistEntryId,
                ],
            );
        } finally {
            if ($previous instanceof Tenant) {
                $tenants->set($previous);
            } else {
                $tenants->forget();
            }
        }
    }
}
