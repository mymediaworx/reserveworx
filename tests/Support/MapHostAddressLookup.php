<?php

namespace Tests\Support;

use App\Services\Webhooks\HostAddressLookup;

final class MapHostAddressLookup implements HostAddressLookup
{
    /**
     * @param  array<string, list<string>>  $map
     */
    public function __construct(private array $map) {}

    /**
     * @param  list<string>  $addresses
     */
    public function set(string $host, array $addresses): void
    {
        $this->map[strtolower($host)] = $addresses;
    }

    public function addresses(string $host): array
    {
        return $this->map[strtolower($host)] ?? [];
    }
}
