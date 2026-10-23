<?php

namespace App\Console\Commands;

use App\Enums\TenantStatus;
use App\Models\RentalCheckout;
use App\Models\Tenant;
use App\Services\Notifications\BookingEvents;
use App\Tenancy\TenantManager;
use Carbon\Carbon;
use Illuminate\Console\Command;

class FlagOverdueRentals extends Command
{
    protected $signature = 'rentals:flag-overdue';

    protected $description = 'Queue one overdue hook for each rental still out past its due time. No email is sent.';

    public function handle(TenantManager $tenants, BookingEvents $events): int
    {
        Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Onboarding->value])
            ->orderBy('id')
            ->each(function (Tenant $tenant) use ($tenants, $events): void {
                $previous = $tenants->get();
                $tenants->set($tenant);

                try {
                    RentalCheckout::query()
                        ->whereNull('returned_at')
                        ->where('due_at', '<', Carbon::now('UTC'))
                        ->orderBy('id')
                        ->each(function (RentalCheckout $checkout) use ($events): void {
                            $events->overdue($checkout);
                        });
                } finally {
                    if ($previous instanceof Tenant) {
                        $tenants->set($previous);
                    } else {
                        $tenants->forget();
                    }
                }
            });

        return self::SUCCESS;
    }
}
