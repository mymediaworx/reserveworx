<?php

namespace App\Http\Controllers\Web;

use App\Enums\WaitlistStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\WaitlistEntry;
use App\Services\AuditLogger;
use App\Support\Display;
use App\Support\DisplayZone;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class WaitlistLeaveController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function leave(Request $request, WaitlistEntry $waitlistEntry): View
    {
        $waitlistEntry->loadMissing('service.location');

        if ($request->isMethod('post') && $waitlistEntry->status === WaitlistStatus::Waiting) {
            $waitlistEntry->status = WaitlistStatus::Cancelled;
            $waitlistEntry->save();
            $this->audit->log('waitlist.cancelled', $waitlistEntry, ['source' => 'guest'], request: $request);
            $waitlistEntry = $waitlistEntry->fresh() ?? $waitlistEntry;
            $waitlistEntry->loadMissing('service.location');
        }

        return view('widget.waitlist-leave', [
            'entry' => $waitlistEntry,
            'when' => $this->when($waitlistEntry),
            'serviceName' => $this->serviceName($waitlistEntry),
        ]);
    }

    private function serviceName(WaitlistEntry $entry): string
    {
        $name = $entry->service?->name;

        return is_string($name) && trim($name) !== '' ? trim($name) : 'this time';
    }

    private function when(WaitlistEntry $entry): string
    {
        $zone = DisplayZone::forLocation($entry->service?->location instanceof Location ? $entry->service->location : null);

        return Display::dateTimeWithZone($entry->starts_at, $zone);
    }
}
