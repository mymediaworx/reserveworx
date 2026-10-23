<?php

namespace App\Services\Allocation;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That idempotency key was already used for a different request.');
    }
}
