<?php

namespace App\Enums;

enum IntegrationType: string
{
    case BILLING = 'billing';
    case PAYMENT_GATEWAY = 'payment_gateway';
    case MESSAGING = 'messaging';

    public function getLabel(): string
    {
        return match ($this) {
            self::BILLING => 'Facturación',
            self::PAYMENT_GATEWAY => 'Pasarela de Pago',
            self::MESSAGING => 'Mensajería',
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