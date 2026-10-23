<?php

namespace App\Http\Middleware;

use App\Support\Hosts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform pages exist only on the platform host, never on a tenant domain.
 */
class EnsurePlatformHost
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(Hosts::isPlatform($request), 404);

        return $next($request);
    }
}
