<?php

namespace App\Services\CheckIn;

use App\Models\Booking;
use App\Models\Domain;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\URL;

/**
 * A permanent signed URL on the workspace's verified domain.
 * Opening it is what checks the booking in. The signature covers the host and the booking id.
 */
final class CheckInLink
{
    public function url(Booking $booking): ?string
    {
        $origin = $this->origin($booking->tenant_id);

        if ($origin === null) {
            return null;
        }

        URL::forceRootUrl($origin);

        try {
            return URL::signedRoute('staff.check-in.arrive', ['booking' => $booking->id]);
        } finally {
            URL::forceRootUrl(null);
        }
    }

    private function origin(string $tenantId): ?string
    {
        $hostname = Domain::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->value('hostname');

        if (! is_string($hostname) || $hostname === '') {
            return null;
        }

        $scheme = app()->environment('production') ? 'https' : 'http';

        return $scheme.'://'.$hostname;
    }
}
