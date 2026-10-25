<?php

namespace App\Models\Concerns;

use App\Tenancy\StaffLocationScope;

trait LimitsStaffLocations
{
    public static function bootLimitsStaffLocations(): void
    {
        static::addGlobalScope(new StaffLocationScope(
            static::staffLocationColumn(),
            static::staffLocationIncludesUnassigned(),
        ));
    }

    public static function staffLocationColumn(): string
    {
        return 'location_id';
    }

    public static function staffLocationIncludesUnassigned(): bool
    {
        return false;
    }
}
