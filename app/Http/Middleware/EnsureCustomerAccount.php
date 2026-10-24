<?php

namespace App\Http\Middleware;

use App\Models\CustomerAccount;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCustomerAccount
{
    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();
        $tenantId = $this->tenants->id();

        if (! $account instanceof CustomerAccount || $tenantId === null || $account->tenant_id !== $tenantId || $account->email_verified_at === null) {
            abort(401);
        }

        return $next($request);
    }
}
