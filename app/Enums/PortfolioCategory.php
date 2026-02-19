<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PortfolioCategory: string implements HasColor, HasLabel
{
    case VentaFresca = 'venta_fresca';
    case PostVenta = 'post_venta';

    public function getLabel(): string
    {
        return match ($this) {
            self::VentaFresca => 'Venta Fresca',
            self::PostVenta => 'Post-venta',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::VentaFresca => 'info',
            self::PostVenta => 'success',
        };
    }
}
