<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Web\Concerns\RendersStaffPage;
use App\Models\Booking;
use App\Services\CheckIn\ArrivalCheckIn;
use App\Services\CheckIn\CheckInArrival;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Opening the signed link checks the booking in. A missing waiver stops that
 * until staff post a reason. Repeating the link does not write a second audit row.
 */
class StaffCheckInController extends Controller
{
    use RendersStaffPage;

    public function __construct(private readonly ArrivalCheckIn $checkIn) {}

    public function show(Request $request, Booking $booking): View
    {
        $this->authorize('checkIn', $booking);

        $arrival = $this->checkIn->arrive($booking, $this->staffUser($request));
        $arrival->booking->loadMissing('service');

        return $this->page($arrival);
    }

    public function override(Request $request, Booking $booking): RedirectResponse
    {
        $this->authorize('checkIn', $booking);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);
        $arrival = $this->checkIn->arrive($booking, $this->staffUser($request), $validated['reason']);
        $redirect = redirect()->to($request->fullUrl());

        if ($arrival->checkedInNow) {
            return $redirect->with('status', 'Checked in.');
        }

        if ($arrival->already) {
            return $redirect->with('status', 'Already checked in.');
        }

        return $redirect->with('problem', $arrival->refused
            ? 'This booking cannot be checked in.'
            : 'Waivers are still missing.');
    }

    private function page(CheckInArrival $arrival): View
    {
        return $this->staffPage('Check in', 'staff.check-in-arrive', [
            'arrival' => $arrival,
        ]);
    }
}
