<?php

namespace App\Enums;

enum IntegrationName: string
{
    case DEFONTANA = 'defontana';
    case FACTURACION_CL = 'facturacion_cl';
    case WHATSAPP = 'whatsapp';

    public function getLabel(): string
    {
        return match ($this) {
            self::DEFONTANA => 'Defontana',
            self::FACTURACION_CL => 'Facturación.cl',
            self::WHATSAPP => 'WhatsApp',
        };
    }

    public static function getSelectOptions(): array
    {
        return array_combine(
            array_column(self::cases(), 'value'),
            array_map(fn (self $case) => $case->getLabel(), self::cases()),
        );
    }

    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}