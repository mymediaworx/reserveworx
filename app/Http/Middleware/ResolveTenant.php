<?php

namespace App\Http\Middleware;

use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Support\Hostname;
use App\Tenancy\TenancyException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $host = Hostname::normalize($request->getHost());

        if (in_array($host, config('reserveworx.platform_domains', []), true)) {
            throw TenancyException::notFound();
        }

        $domain = Domain::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('hostname', $host)
            ->whereNotNull('verified_at')
            ->first();

        if ($domain === null) {
            throw TenancyException::notFound();
        }

        $tenant = $domain->tenant;

        if ($tenant === null || in_array($tenant->status, [TenantStatus::Suspended, TenantStatus::Terminated], true)) {
            throw TenancyException::unavailable();
        }

        $this->rejectClientTenantId($request, $tenant->id);
        $this->tenants->set($tenant);
        $request->attributes->set('tenant', $tenant);

        try {
            return $next($request);
        } finally {
            $this->tenants->forget();
        }
    }

    private function rejectClientTenantId(Request $request, string $tenantId): void
    {
        $supplied = [];

        if ($request->headers->has('X-Tenant-Id')) {
            $supplied[] = $request->headers->get('X-Tenant-Id');
        }

        if ($request->exists('tenant_id')) {
            $supplied[] = $request->input('tenant_id');
        }

        foreach ($supplied as $value) {
            if (! is_string($value) || $value !== $tenantId) {
                throw TenancyException::mismatch();
            }
        }
    }
}
