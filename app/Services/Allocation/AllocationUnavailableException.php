<?php

namespace App\Services\Allocation;

use RuntimeException;

class AllocationUnavailableException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $offers
     */
    public function __construct(string $message = 'That time is no longer available.', public readonly array $offers = [])
    {
        parent::__construct($message);
    }
}
