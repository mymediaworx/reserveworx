<?php

namespace App\Services\Notifications;

use App\Enums\BookingStatus;
use App\Enums\TenantStatus;
use App\Events\BookingReminderDue;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Queues one reminder per confirmed booking and configured offset.
 * An offset is elapsed minutes before the stored UTC start, so a daylight-saving
 * transition does not turn it into a calendar day. The location timezone is
 * copied onto the hook for the local start. Tenants do not have a timezone.
 */
final class BookingReminders
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function dispatchDue(): void
    {
        Tenant::query()
            ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Onboarding->value])
            ->orderBy('id')
            ->each(function (Tenant $tenant): void {
                $previous = $this->tenants->get();
                $this->tenants->set($tenant);

                try {
                    $this->dispatchForCurrentTenant();
                } finally {
                    if ($previous instanceof Tenant) {
                        $this->tenants->set($previous);
                    } else {
                        $this->tenants->forget();
                    }
                }
            });
    }

    private function dispatchForCurrentTenant(): void
    {
        $setting = TenantSetting::query()->first();
        $offsets = $setting instanceof TenantSetting
            ? $setting->reminderOffsets()
            : TenantSetting::DEFAULT_REMINDER_OFFSETS_MINUTES;

        $now = CarbonImmutable::now('UTC');

        foreach ($offsets as $offset) {
            Booking::query()
                ->where('status', BookingStatus::Confirmed)
                ->where('starts_at', '>', $now)
                ->where('starts_at', '<=', $now->addMinutes($offset))
                ->whereDoesntHave('reminders', function ($query) use ($offset): void {
                    $query->where('offset_minutes', $offset);
                })
                ->orderBy('id')
                ->each(function (Booking $booking) use ($offset): void {
                    $this->claim($booking, $offset);
                });
        }
    }

    private function claim(Booking $booking, int $offset): void
    {
        try {
            DB::transaction(function () use ($booking, $offset): void {
                BookingReminder::query()->create([
                    'tenant_id' => $booking->tenant_id,
                    'booking_id' => $booking->id,
                    'offset_minutes' => $offset,
                ]);

                BookingReminderDue::dispatch($booking->tenant_id, $booking->id, $offset);
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
        }
    }
}
