<?php

namespace App\Services\Allocation;

use App\Enums\ResourceType;

interface ConsumesInventory
{
    public function resourceType(): ResourceType;

    public function resourceId(): string;

    public function unitsFor(int $participants): int;
}
