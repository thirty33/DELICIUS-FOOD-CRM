<?php

namespace App\Enums;

enum PermissionName: string
{
    case CONSOLIDADO = 'Consolidado';
    case INDIVIDUAL = 'Individual';

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}