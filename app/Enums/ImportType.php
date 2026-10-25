<?php

namespace App\Enums;

enum ImportType: string
{
    case Customers = 'customers';
    case Inventory = 'inventory';

    public function label(): string
    {
        return match ($this) {
            self::Customers => 'Customers',
            self::Inventory => 'Inventory items',
        };
    }
}
