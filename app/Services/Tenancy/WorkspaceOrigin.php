<?php

namespace App\Services\Tenancy;

use App\Models\Domain;
use App\Support\Hostname;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\URL;

/**
 * The public origin for links a customer or staff member opens later.
 * In production a seeded .test hostname is not a real site, and APP_URL is not a tenant.
 */
final class WorkspaceOrigin
{
    public function baseUrl(string $tenantId): ?string
    {
        $hostname = $this->hostname($tenantId);

        if ($hostname === null) {
            return null;
        }

        $scheme = app()->environment('production') ? 'https' : 'http';

        return $scheme.'://'.$hostname;
    }

    /**
     * Laravel replaces a forced root's scheme with the current request scheme.
     * A queued email or an http test request must still keep this origin.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function generate(string $origin, callable $callback): mixed
    {
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        URL::forceRootUrl($origin);
        URL::forceScheme(is_string($scheme) && $scheme !== '' ? $scheme : null);

        try {
            return $callback();
        } finally {
            URL::forceRootUrl(null);
            URL::forceScheme(null);
        }
    }

    public function hostname(string $tenantId): ?string
    {
        $rows = Domain::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereNotNull('verified_at')
            ->orderByDesc('is_primary')
            ->orderBy('hostname')
            ->pluck('hostname');

        $names = [];

        foreach ($rows as $hostname) {
            if (! is_string($hostname) || $hostname === '') {
                continue;
            }

            if (app()->environment('production') && $this->isSeededTestHost($hostname)) {
                continue;
            }

            $names[] = $hostname;
        }

        return $names[0] ?? null;
    }

    public function isSeededTestHost(string $hostname): bool
    {
        $host = Hostname::normalize($hostname);

        return $host === 'localhost'
            || $host === 'test'
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.localhost');
    }
}
