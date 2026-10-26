<?php

namespace App\Enums;

enum ResourceType: string
{
    case Asset = 'asset';
    case Pool = 'pool';
    case Fleet = 'fleet';
}
