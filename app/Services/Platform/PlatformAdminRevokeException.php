<?php

namespace App\Services\Platform;

use RuntimeException;

class PlatformAdminRevokeException extends RuntimeException
{
    public function __construct(string $message, public int $status)
    {
        parent::__construct($message);
    }
}
