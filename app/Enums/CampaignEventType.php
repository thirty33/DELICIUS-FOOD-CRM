<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CampaignEventType: string implements HasLabel, HasColor
{
    case MENU_CREATED = 'menu_created';
    case MENU_CLOSING = 'menu_closing';
    case CATEGORY_CLOSING = 'category_closing';
    case NO_ORDER_PLACED = 'no_order_placed';

    public function getLabel(): ?string
    {
        return match ($this) {
            self::MENU_CREATED => 'Creación de menú',
            self::MENU_CLOSING => 'Cierre de menú',
            self::CATEGORY_CLOSING => 'Cierre de categoría',
            self::NO_ORDER_PLACED => 'Sin pedido realizado',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::MENU_CREATED => 'success',
            self::MENU_CLOSING => 'warning',
            self::CATEGORY_CLOSING => 'info',
            self::NO_ORDER_PLACED => 'danger',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::MENU_CREATED => 'Se dispara cuando se crea un nuevo menú',
            self::MENU_CLOSING => 'Se dispara X horas antes del cierre del menú',
            self::CATEGORY_CLOSING => 'Se dispara X horas antes del cierre de una categoría',
            self::NO_ORDER_PLACED => 'Se dispara cuando un usuario no ha realizado pedido antes del cierre',
        };
    }
}