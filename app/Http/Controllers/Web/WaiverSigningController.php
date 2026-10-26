<?php

namespace App\Http\Controllers\Web;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\Branding\CurrentWorkspaceBranding;
use App\Services\CheckIn\WaiverGate;
use App\Services\Waivers\WaiverForm;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The customer's signed waiver page (#134). It lists the workspace's current
 * waiver versions in full and stores one signature per named person, or one
 * for the booking when nobody is named, through the same path as the API.
 */
class WaiverSigningController extends Controller
{
    public function __construct(
        private readonly WaiverGate $gate,
        private readonly WaiverForm $forms,
    ) {}

    public function show(Booking $booking): View
    {
        $this->assertSignable($booking);
        $presented = $this->forms->present($booking);

        return view('waivers.sign', [
            'booking' => $booking->loadMissing(['service', 'location']),
            'businessName' => $this->businessName($booking),
            'waivers' => $presented['waivers'],
            'readiness' => $this->gate->status($booking),
            'people' => $presented['people'],
        ]);
    }

    public function store(Request $request, Booking $booking): RedirectResponse
    {
        $this->assertSignable($booking);

        if (! $this->forms->sign($request, $booking)) {
            return redirect()->to($request->fullUrl());
        }

        return redirect()->to($request->fullUrl())->with('status', 'Signed. Thank you.');
    }

    private function assertSignable(Booking $booking): void
    {
        abort_unless(in_array($booking->status, [BookingStatus::Held, BookingStatus::Confirmed], true), 404);
        abort_if($booking->ends_at->lessThan(now()->subDay()), 404);
    }

    private function businessName(Booking $booking): string
    {
        $branding = app(CurrentWorkspaceBranding::class)->resolve();

        return $branding->displayName !== '' ? $branding->displayName : (string) $booking->tenant?->name;
    }
}
