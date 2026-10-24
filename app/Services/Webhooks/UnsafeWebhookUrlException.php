<?php

namespace App\Services\Webhooks;

use RuntimeException;

final class UnsafeWebhookUrlException extends RuntimeException
{
    public static function refused(): self
    {
        return new self('Use a public HTTPS URL.');
    }
}
