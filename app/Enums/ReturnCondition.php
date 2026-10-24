<?php

namespace App\Enums;

enum ReturnCondition: string
{
    case Ok = 'ok';
    case Damaged = 'damaged';
    case Missing = 'missing';
}
