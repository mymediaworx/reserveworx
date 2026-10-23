<?php

namespace App\Enums;

enum WaitlistStatus: string
{
    case Waiting = 'waiting';
    case Promoted = 'promoted';
    case Cancelled = 'cancelled';
}
