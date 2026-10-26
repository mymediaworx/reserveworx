<?php

namespace App\Services\Integrations;

use RuntimeException;

class GhlRequestFailed extends RuntimeException
{
    public function __construct(
        public readonly ?int $httpStatus,
        public readonly string $kind,
        bool $unexpected = false,
        public readonly bool $wrongLocation = false,
    ) {
        $message = match (true) {
            $wrongLocation => 'GoHighLevel update returned a different location.',
            $unexpected || $httpStatus === null => 'GoHighLevel '.$kind.' returned an unexpected response.',
            default => 'GoHighLevel '.$kind.' returned HTTP '.$httpStatus.'.',
        };

        parent::__construct($message);
    }
}
