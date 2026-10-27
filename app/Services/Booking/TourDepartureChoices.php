<?php

namespace App\Services\Booking;

use App\Enums\DepartureStatus;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\Service;
use App\Support\Display;
use App\Support\DisplayZone;

/**
 * Other departures a tour booking can move to. Availability is a hint.
 * The allocation transaction is the authority.
 */
final class TourDepartureChoices
{
    /**
     * @return list<array{id: string, label: string, available: bool, note: string}>
     */
    public function forBooking(Booking $booking): array
    {
        if ($booking->departure_id === null || $booking->departure_id === '') {
            return [];
        }

        $departures = Departure::query()
            ->with('location')
            ->where('service_id', $booking->service_id)
            ->where('status', DepartureStatus::Scheduled)
            ->where('id', '!=', $booking->departure_id)
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $counts = Departure::headcountsFor($departures);
        $service = $booking->relationLoaded('service') && $booking->service instanceof Service
            ? $booking->service
            : Service::query()->find($booking->service_id);
        $maximum = $service instanceof Service ? $service->max_participants : null;
        $choices = [];

        foreach ($departures as $departure) {
            $count = $counts[$departure->id] ?? 0;
            $cap = $departure->seats ?? $maximum;
            $note = '';
            $available = true;

            if ($departure->private && $count > 0) {
                $available = false;
                $note = 'Private';
            } elseif ($cap !== null && $count + $booking->participants > $cap) {
                $available = false;
                $note = 'Full';
            }

            $label = Display::rangeWithZone(
                $departure->starts_at,
                $departure->ends_at,
                DisplayZone::forLocation($departure->location),
            );

            if ($note !== '') {
                $label .= ' ('.$note.')';
            }

            $choices[] = [
                'id' => $departure->id,
                'label' => $label,
                'available' => $available,
                'note' => $note,
            ];
        }

        return $choices;
    }
}
