<?php

namespace App\Enums;

enum OrderProductionStatus: string
{
    case FULLY_PRODUCED = 'completamente_producido';
    case PARTIALLY_PRODUCED = 'parcialmente_producido';
    case NOT_PRODUCED = 'no_producido';

    public function label(): string
    {
        return match ($this) {
            self::FULLY_PRODUCED => 'Completamente Producido',
            self::PARTIALLY_PRODUCED => 'Parcialmente Producido',
            self::NOT_PRODUCED => 'No Producido',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::FULLY_PRODUCED => 'success',
            self::PARTIALLY_PRODUCED => 'warning',
            self::NOT_PRODUCED => 'danger',
        };
    }
}
