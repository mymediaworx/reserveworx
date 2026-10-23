<?php

namespace App\Enums;

enum FeePayer: string
{
    case CustomerPaid = 'customer_paid';
    case OperatorAbsorbed = 'operator_absorbed';
}
