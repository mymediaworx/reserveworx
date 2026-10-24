<?php

namespace App\Services\Integrations;

use RuntimeException;

class GhlRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly ?int $httpStatus,
        public readonly string $kind,
        bool $unexpected = false,
    ) {
        $message = $unexpected || $httpStatus === null
            ? 'GoHighLevel '.$kind.' returned an unexpected response.'
            : 'GoHighLevel '.$kind.' returned HTTP '.$httpStatus.'.';

        parent::__construct($message);
    }
}
