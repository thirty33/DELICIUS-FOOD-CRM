<?php

namespace App\Enums;

enum RoleName: string
{
    case ADMIN = 'Admin';
    case CAFE = 'Café';
    case AGREEMENT = 'Convenio';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}