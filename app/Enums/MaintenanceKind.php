<?php

namespace App\Enums;

enum MaintenanceKind: string
{
    case Maintenance = 'maintenance';
    case Block = 'block';

    public function label(): string
    {
        return match ($this) {
            self::Maintenance => 'Maintenance',
            self::Block => 'Temporary block',
        };
    }
}
