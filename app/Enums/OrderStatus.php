<?php

namespace App\Enums;

enum OrderStatus: string
{
    case PENDING = 'PENDING';
    case PARTIALLY_SCHEDULED = 'PARTIALLY_SCHEDULED';
    case PROCESSED = 'PROCESSED';
    case CANCELED = 'CANCELED';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::PARTIALLY_SCHEDULED => 'Parcialmente Agendado',
            self::PROCESSED => 'Procesado',
            self::CANCELED => 'Cancelado',
        };
    }

    public static function getSelectOptions(): array
    {
        return [
            self::PENDING->value => self::PENDING->getLabel(),
            self::PARTIALLY_SCHEDULED->value => self::PARTIALLY_SCHEDULED->getLabel(),
            self::PROCESSED->value => self::PROCESSED->getLabel(),
            self::CANCELED->value => self::CANCELED->getLabel(),
        ];
    }

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
    
}
