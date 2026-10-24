<?php

namespace App\Services\Branding;

use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Http\Request;

/**
 * Resolves the workspace branding for the current request.
 * Tenant context wins. After a controller clears that context, the tenant
 * stored on the request by domain resolution is used instead.
 */
final class CurrentWorkspaceBranding
{
    public function __construct(private readonly TenantManager $tenants) {}

    public function resolve(): WorkspaceBranding
    {
        $tenant = $this->tenant();
        $key = $tenant instanceof Tenant ? $tenant->id : '';
        $request = $this->request();

        if ($request instanceof Request) {
            $cached = $request->attributes->get(self::class);

            if (
                is_array($cached)
                && ($cached['key'] ?? null) === $key
                && ($cached['value'] ?? null) instanceof WorkspaceBranding
            ) {
                return $cached['value'];
            }
        }

        $value = $this->load($tenant);

        if ($request instanceof Request) {
            $request->attributes->set(self::class, ['key' => $key, 'value' => $value]);
        }

        return $value;
    }

    private function load(?Tenant $tenant): WorkspaceBranding
    {
        if (! $tenant instanceof Tenant) {
            return WorkspaceBranding::blank();
        }

        $row = TenantBranding::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->first();

        if (! $row instanceof TenantBranding) {
            return new WorkspaceBranding(
                $tenant->id,
                $tenant->name,
                null,
                TenantBranding::DEFAULT_PRIMARY,
                TenantBranding::DEFAULT_ACCENT,
                null,
                null,
            );
        }

        return new WorkspaceBranding(
            $tenant->id,
            $row->display_name,
            $row->publicLogoUrl($this->request()),
            $row->primary_color,
            $row->accent_color,
            $row->support_email,
            $row->support_phone,
        );
    }

    private function tenant(): ?Tenant
    {
        $current = $this->tenants->get();

        if ($current instanceof Tenant) {
            return $current;
        }

        $request = $this->request();
        $attribute = $request?->attributes->get('tenant');

        return $attribute instanceof Tenant ? $attribute : null;
    }

    private function request(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        return request();
    }
}
