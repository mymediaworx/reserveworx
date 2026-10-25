<?php

namespace App\Enums;

enum ResourceFlag: string
{
    case NeedsAttention = 'needs_attention';
    case Damaged = 'damaged';

    public function label(): string
    {
        return match ($this) {
            self::NeedsAttention => 'Needs attention',
            self::Damaged => 'Damaged',
        };
    }
}
