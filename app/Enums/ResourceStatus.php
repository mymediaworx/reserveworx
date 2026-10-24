<?php

namespace App\Enums;

enum ResourceStatus: string
{
    case Active = 'active';
    case Blocked = 'blocked';
    case Maintenance = 'maintenance';
    case Retired = 'retired';

    /**
     * Statuses a create or update may set. Retirement is a separate action.
     *
     * @return list<string>
     */
    public static function writable(): array
    {
        return [
            self::Active->value,
            self::Blocked->value,
            self::Maintenance->value,
        ];
    }
}
