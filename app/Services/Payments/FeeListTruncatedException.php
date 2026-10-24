<?php

namespace App\Services\Payments;

use RuntimeException;

class FeeListTruncatedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This period has more Stripe fees than one reconciliation can read.');
    }
}
