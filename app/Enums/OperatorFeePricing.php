<?php

namespace App\Enums;

enum OperatorFeePricing: string
{
    case Flat = 'flat';
    case PerPerson = 'per_person';
    case Percent = 'percent';
}
