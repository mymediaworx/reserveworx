<?php

namespace App\Services\Tenancy;

use App\Models\Domain;
use App\Models\Tenant;
use App\Models\TenantBranding;
use App\Tenancy\TenantScope;
use Illuminate\Support\Collection;

/**
 * Keeps a demo workspace's public name and primary hostname aligned.
 * The seeded .test address is only primary when it is the only verified host.
 */
final class DemoWorkspaceIdentity
{
    public function __construct(private readonly WorkspaceOrigin $origins) {}

    public function align(Tenant $tenant): void
    {
        $this->alignDisplayName($tenant);
        $this->alignPrimaryDomain($tenant);
    }

    private function alignDisplayName(Tenant $tenant): void
    {
        $name = trim($tenant->name);
        $name = $name === '' ? 'Workspace' : mb_substr($name, 0, 120);

        $branding = TenantBranding::withoutGlobalScope(TenantScope::class)->firstOrNew([
            'tenant_id' => $tenant->id,
        ]);

        if (! $branding->exists) {
            $branding->primary_color = TenantBranding::DEFAULT_PRIMARY;
            $branding->accent_color = TenantBranding::DEFAULT_ACCENT;
        }

        if ($branding->exists && $branding->display_name === $name) {
            return;
        }

        $branding->display_name = $name;
        $branding->save();
    }

    private function alignPrimaryDomain(Tenant $tenant): void
    {
        $domains = Domain::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->get();

        $public = $domains->filter(function (Domain $domain): bool {
            return $domain->verified_at !== null && ! $this->origins->isSeededTestHost($domain->hostname);
        })->values();

        if ($public->isEmpty()) {
            if (app()->environment('production')) {
                foreach ($domains as $domain) {
                    if ($domain->is_primary && $this->origins->isSeededTestHost($domain->hostname)) {
                        $domain->forceFill(['is_primary' => false])->save();
                    }
                }

                return;
            }

            $seed = $domains->first(function (Domain $domain): bool {
                return $domain->verified_at !== null && $this->origins->isSeededTestHost($domain->hostname);
            });

            if ($seed instanceof Domain) {
                $this->markPrimary($domains, $seed);
            }

            return;
        }

        $primary = $public->first(fn (Domain $domain): bool => (bool) $domain->is_primary)
            ?? $public->sortBy('hostname')->first();

        if ($primary instanceof Domain) {
            $this->markPrimary($domains, $primary);
        }
    }

    /**
     * @param  Collection<int, Domain>  $domains
     */
    private function markPrimary(Collection $domains, Domain $primary): void
    {
        foreach ($domains as $domain) {
            $should = $domain->is($primary);

            if ((bool) $domain->is_primary === $should) {
                continue;
            }

            $domain->forceFill(['is_primary' => $should])->save();
        }
    }
}
