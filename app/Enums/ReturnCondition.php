<?php

namespace App\Enums;

enum ReturnCondition: string
{
    case Ok = 'ok';
    case Good = 'good';
    case NeedsAttention = 'needs_attention';
    case Damaged = 'damaged';
    case Missing = 'missing';

    /**
     * Conditions the rental API already accepts.
     *
     * @return list<string>
     */
    public static function apiValues(): array
    {
        return [
            self::Ok->value,
            self::Damaged->value,
            self::Missing->value,
        ];
    }

    /**
     * Conditions recorded from the staff returns page.
     *
     * @return list<string>
     */
    public static function staffValues(): array
    {
        return [
            self::Good->value,
            self::NeedsAttention->value,
            self::Damaged->value,
        ];
    }
}
