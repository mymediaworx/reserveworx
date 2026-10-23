<?php

namespace App\Http\Middleware;

use App\Models\Domain;
use App\Support\Hostname;
use App\Tenancy\TenancyException;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureKnownHost
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = Hostname::normalize($request->getHost());
        $platformDomains = config('reserveworx.platform_domains', []);

        if (in_array($host, $platformDomains, true)) {
            return $next($request);
        }

        $known = Domain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('hostname', $host)
            ->exists();

        if (! $known) {
            throw TenancyException::notFound();
        }

        return $next($request);
    }
}
