<?php

namespace App\Enums;

enum DepartureStatus: string
{
    case Scheduled = 'scheduled';
    case Cancelled = 'cancelled';
}
