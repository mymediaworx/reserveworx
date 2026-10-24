<?php

namespace App\Services\Booking;

use App\Enums\WaitlistStatus;
use App\Jobs\RecordWaitlistPromotion;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;

class WaitlistPromoter
{
    public function __construct(
        private readonly Allocator $allocator,
        private readonly TenantManager $tenants,
    ) {}

    public function afterRelease(Booking $freed, ?CarbonImmutable $windowStart = null, ?CarbonImmutable $windowEnd = null): void
    {
        $start = $windowStart ?? CarbonImmutable::instance($freed->starts_at)->utc();
        $end = $windowEnd ?? CarbonImmutable::instance($freed->ends_at)->utc();

        if ($end->lessThanOrEqualTo($start)) {
            return;
        }

        $tenant = Tenant::query()->find($freed->tenant_id);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $previous = $this->tenants->id();
        $this->tenants->set($tenant);

        try {
            $entries = WaitlistEntry::query()
                ->where('service_id', $freed->service_id)
                ->where('status', WaitlistStatus::Waiting)
                ->where('starts_at', '<', $end)
                ->where('ends_at', '>', $start)
                ->when(
                    $freed->departure_id !== null,
                    fn ($query) => $query->where('departure_id', $freed->departure_id),
                    fn ($query) => $query->whereNull('departure_id'),
                )
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            foreach ($entries as $entry) {
                $this->promote($entry);
            }
        } finally {
            $this->restoreTenant($previous);
        }
    }

    public function promote(WaitlistEntry $entry): bool
    {
        if ($entry->status !== WaitlistStatus::Waiting) {
            return false;
        }

        $intent = new AllocationIntent(
            $entry->tenant_id,
            $entry->service_id,
            CarbonImmutable::instance($entry->starts_at)->utc(),
            CarbonImmutable::instance($entry->ends_at)->utc(),
            $entry->participants,
            null,
            $entry->customer_name,
            $entry->customer_email,
            $entry->departure_id,
            customerPhone: $entry->customer_phone,
            variantId: $entry->service_variant_id,
        );

        try {
            $booking = $this->allocator->hold($intent);
        } catch (AllocationUnavailableException) {
            return false;
        }

        $entry->status = WaitlistStatus::Promoted;
        $entry->booking_id = $booking->id;
        $entry->save();

        RecordWaitlistPromotion::dispatch($entry->tenant_id, $booking->id, $entry->id);

        return true;
    }

    private function restoreTenant(?string $previous): void
    {
        if ($previous === null) {
            $this->tenants->forget();

            return;
        }

        if ($previous === $this->tenants->id()) {
            return;
        }

        $tenant = Tenant::query()->find($previous);

        if ($tenant instanceof Tenant) {
            $this->tenants->set($tenant);
        }
    }
}
