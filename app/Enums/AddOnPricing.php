<?php

namespace App\Enums;

enum AddOnPricing: string
{
    case Flat = 'flat';
    case PerPerson = 'per_person';
    case Each = 'each';
}
