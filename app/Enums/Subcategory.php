<?php

namespace App\Enums;

enum Subcategory: string
{
    case MAIN_DISH = 'PLATO DE FONDO'; // Valor y etiqueta

    /**
     * Obtiene la etiqueta (label) de la subcategoría.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::MAIN_DISH => 'Plato de fondo',
        };
    }

    /**
     * Obtiene las opciones para un campo Select.
     */
    public static function getSelectOptions(): array
    {
        return [
            self::MAIN_DISH->value => self::MAIN_DISH->getLabel(),
        ];
    }
}