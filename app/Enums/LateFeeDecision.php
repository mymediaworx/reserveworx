<?php

namespace App\Enums;

enum LateFeeDecision: string
{
    case Applied = 'applied';
    case Waived = 'waived';
}
