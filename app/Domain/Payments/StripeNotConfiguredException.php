<?php

namespace App\Domain\Payments;

use RuntimeException;

class StripeNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Stripe is not configured. Add the platform secret before taking online payments.');
    }
}
