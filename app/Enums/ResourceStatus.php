<?php

namespace App\Enums;

enum ResourceStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Maintenance = 'maintenance';
}
