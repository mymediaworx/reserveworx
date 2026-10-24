<?php

namespace App\Services\Privacy;

use App\Models\Customer;
use App\Models\CustomerDataDeletion;

final class CustomerErasureResult
{
    public function __construct(
        public bool $refused,
        public bool $idempotent,
        public ?CustomerDataDeletion $deletion,
        public ?Customer $customer,
    ) {}
}
