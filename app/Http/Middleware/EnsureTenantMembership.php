<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Tenancy\Access;
use App\Tenancy\TenancyException;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantMembership
{
    public function __construct(
        private readonly TenantManager $tenants,
        private readonly Access $access,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $this->tenants->id();

        if ($tenantId === null) {
            throw TenancyException::notFound();
        }

        $user = $request->user();

        if (! $user instanceof User) {
            abort(401);
        }

        $membership = $this->access->membership($user, $tenantId);

        if ($membership === null) {
            throw TenancyException::forbidden();
        }

        $request->attributes->set('membership', $membership);

        return $next($request);
    }
}
