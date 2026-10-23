<?php

namespace App\Http\Middleware;

use App\Support\Hosts;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps the Livewire update endpoint, which serves both tenant hosts and the
 * platform host. On a tenant host the tenant is resolved exactly as for any
 * other tenant route and stays set for the whole request, so components only
 * ever see that tenant's rows. Livewire's own persistent middleware cannot do
 * this: it runs middleware with an empty destination, and ResolveTenant clears
 * the context when its destination returns.
 */
class ResolveTenantUnlessPlatform
{
    public function __construct(private readonly ResolveTenant $resolve) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Hosts::isPlatform($request)) {
            return $next($request);
        }

        return $this->resolve->handle($request, $next);
    }
}
