<?php

namespace App\Tenancy;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class TenancyException extends HttpException
{
    public static function notFound(): self
    {
        return new self(404, 'No workspace is available at this address.');
    }

    public static function unavailable(): self
    {
        return new self(403, 'This workspace is unavailable.');
    }

    public static function mismatch(): self
    {
        return new self(403, 'The workspace id does not match this address.');
    }

    public static function forbidden(): self
    {
        return new self(403, 'You do not have access to this workspace.');
    }
}
