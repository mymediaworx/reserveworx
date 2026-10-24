<?php

namespace App\Services\Team;

use App\Models\Membership;

final class InviteResult
{
    public function __construct(
        public Membership $membership,
        public bool $mailed,
    ) {}
}
