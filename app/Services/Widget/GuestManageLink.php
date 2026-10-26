<?php

namespace App\Services\Widget;

use App\Models\Booking;
use App\Models\Tenant;
use App\Services\Tenancy\WorkspaceOrigin;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * A signed link to the guest manage page for one booking. It uses the
 * workspace's hosted booking token and verified host, and lasts until a day
 * after the visit, or seven days, whichever is later.
 */
final class GuestManageLink
{
    public function __construct(
        private readonly WidgetTokens $tokens,
        private readonly WorkspaceOrigin $origins,
    ) {}

    public function url(Booking $booking): ?string
    {
        $tenant = Tenant::query()->find($booking->tenant_id);
        $origin = $this->origins->baseUrl($booking->tenant_id);

        if (! $tenant instanceof Tenant || $origin === null) {
            return null;
        }

        try {
            $plain = $this->tokens->hostedPlain($tenant);
        } catch (RuntimeException) {
            return null;
        }

        $end = CarbonImmutable::instance($booking->ends_at)->utc()->addDay();
        $minimum = CarbonImmutable::now()->utc()->addDays(7);
        $expires = $end->greaterThan($minimum) ? $end : $minimum;

        return $this->origins->generate(
            $origin,
            fn (): string => URL::temporarySignedRoute('widget.manage', $expires, [
                'token' => $plain,
                'booking' => $booking->id,
            ]),
        );
    }
}
