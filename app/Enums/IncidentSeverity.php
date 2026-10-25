<?php

namespace App\Enums;

enum IncidentSeverity: string
{
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Serious = 'serious';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Minor',
            self::Moderate => 'Moderate',
            self::Serious => 'Serious',
        };
    }
}
