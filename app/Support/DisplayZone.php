<?php

namespace App\Support;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;

/**
 * The clock a guest or staff member sees.
 * A location zone wins when it is a real IANA name. Otherwise the workspace
 * zone is used. The last resort is America/New_York, never UTC.
 */
final class DisplayZone
{
    public static function forLocation(?Location $location): string
    {
        if ($location instanceof Location) {
            $zone = self::named($location->timezone);

            if ($zone !== null) {
                return $zone;
            }
        }

        $tenantId = $location instanceof Location ? $location->tenant_id : null;

        if (is_string($tenantId) && $tenantId !== '') {
            return self::forTenantId($tenantId);
        }

        return self::forTenant(app(TenantManager::class)->get());
    }

    public static function resolve(mixed $zone, ?Tenant $tenant = null): string
    {
        $named = self::named($zone);

        if ($named !== null) {
            return $named;
        }

        if ($tenant instanceof Tenant) {
            return self::forTenant($tenant);
        }

        return self::forTenant(app(TenantManager::class)->get());
    }

    public static function forTenant(?Tenant $tenant): string
    {
        if (! $tenant instanceof Tenant) {
            return TenantSetting::DEFAULT_TIMEZONE;
        }

        return self::forTenantId($tenant->id);
    }

    public static function forTenantId(string $tenantId): string
    {
        return self::named(TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->value('timezone')) ?? TenantSetting::DEFAULT_TIMEZONE;
    }

    public static function named(mixed $zone): ?string
    {
        if (! is_string($zone) || $zone === '' || ! isset(self::identifiers()[$zone])) {
            return null;
        }

        return $zone;
    }

    /**
     * @return array<string, true>
     */
    private static function identifiers(): array
    {
        /** @var array<string, true>|null $zones */
        static $zones = null;

        if (! is_array($zones)) {
            $zones = array_fill_keys(timezone_identifiers_list(), true);
        }

        return $zones;
    }
}
