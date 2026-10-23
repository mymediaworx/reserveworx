<?php

namespace App\Enums;

enum ScheduleOwnerType: string
{
    case Location = 'location';
    case Asset = 'asset';
    case Pool = 'pool';
}
