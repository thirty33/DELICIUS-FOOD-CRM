<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WindowStatus: string implements HasColor, HasLabel
{
    case AwaitingResponse = 'awaiting_response';
    case Active = 'active';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::AwaitingResponse => __('Esperando respuesta del cliente'),
            self::Active => __('Ventana activa'),
            self::Expired => __('Ventana expirada'),
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::AwaitingResponse => 'warning',
            self::Active => 'success',
            self::Expired => 'danger',
        };
    }
}
