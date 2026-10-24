<?php

namespace App\Support;

use App\Models\Domain;
use App\Models\Tenant;
use App\Tenancy\TenantScope;

/**
 * The public address a new business asks for: {slug}.{booking apex}.
 */
final class Subdomain
{
    public const PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    public function apex(): string
    {
        $apex = config('reserveworx.booking_apex');

        if (! is_string($apex) || $apex === '') {
            return 'reserveworx.com';
        }

        return strtolower(trim($apex));
    }

    public function normalize(string $slug): string
    {
        return strtolower(trim($slug));
    }

    public function hostname(string $slug): string
    {
        return $this->normalize($slug).'.'.$this->apex();
    }

    /**
     * @return 'available'|'taken'|'reserved'|'invalid'
     */
    public function status(string $slug): string
    {
        $slug = $this->normalize($slug);

        if (! $this->isWellFormed($slug)) {
            return 'invalid';
        }

        if ($this->isReserved($slug)) {
            return 'reserved';
        }

        if ($this->isTaken($slug)) {
            return 'taken';
        }

        return 'available';
    }

    public function isWellFormed(string $slug): bool
    {
        $slug = $this->normalize($slug);

        return strlen($slug) >= 3
            && strlen($slug) <= 63
            && preg_match(self::PATTERN, $slug) === 1;
    }

    public function isReserved(string $slug): bool
    {
        $slug = $this->normalize($slug);

        if (in_array($slug, $this->reservedLabels(), true)) {
            return true;
        }

        $platform = config('reserveworx.platform_domains', []);

        return is_array($platform) && in_array($this->hostname($slug), $platform, true);
    }

    public function isTaken(string $slug): bool
    {
        $slug = $this->normalize($slug);

        if (Tenant::query()->where('slug', $slug)->exists()) {
            return true;
        }

        return Domain::withoutGlobalScope(TenantScope::class)
            ->where('hostname', $this->hostname($slug))
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function reservedLabels(): array
    {
        $configured = config('reserveworx.reserved_subdomains', []);
        $labels = is_array($configured) ? $configured : [];
        $platform = config('reserveworx.platform_domains', []);

        if (is_array($platform)) {
            foreach ($platform as $host) {
                if (is_string($host) && $host !== '') {
                    $labels[] = explode('.', strtolower($host))[0];
                }
            }
        }

        $clean = [];

        foreach ($labels as $label) {
            if (is_string($label) && $label !== '') {
                $clean[] = strtolower($label);
            }
        }

        return array_values(array_unique($clean));
    }
}
