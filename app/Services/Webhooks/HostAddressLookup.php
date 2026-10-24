<?php

namespace App\Services\Webhooks;

interface HostAddressLookup
{
    /**
     * @return list<string>
     */
    public function addresses(string $host): array;
}
