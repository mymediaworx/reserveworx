<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which kind of host a request arrived on. Platform hosts never imply a tenant.
 */
final class Hosts
{
    public static function isPlatform(Request $request): bool
    {
        return in_array(
            Hostname::normalize($request->getHost()),
            config('reserveworx.platform_domains', []),
            true,
        );
    }
}
