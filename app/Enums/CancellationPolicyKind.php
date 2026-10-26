<?php

namespace App\Enums;

enum CancellationPolicyKind: string
{
    case CustomerCancel = 'customer_cancel';
    case NoShow = 'no_show';
}
