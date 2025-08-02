<?php

namespace App\Enums;

enum Subcategory: string
{
    case PLATO_DE_FONDO = 'PLATO DE FONDO';
    case ENTRADA = 'ENTRADA';
    case CALIENTE = 'CALIENTE';
    case HIPOCALORICO = 'HIPOCALORICO';
    case FRIA = 'FRIA';
    case SANDWICH = 'SANDWICH';
    case POSTRE = 'POSTRE';
    case BEBESTIBLE = 'BEBESTIBLE';
    case PAN_DE_ACOMPANAMIENTO = 'PAN DE ACOMPAÑAMIENTO';
    case CUBIERTOS = 'CUBIERTOS';

    /**
     * Obtiene la etiqueta (label) de la subcategoría.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::PLATO_DE_FONDO => 'Plato de fondo',
            self::ENTRADA => 'Entrada',
            self::CALIENTE => 'Caliente',
            self::HIPOCALORICO => 'Hipocalórico',
            self::FRIA => 'Fría',
            self::SANDWICH => 'Sandwich',
            self::POSTRE => 'Postre',
            self::BEBESTIBLE => 'Bebestible',
            self::PAN_DE_ACOMPANAMIENTO => 'Pan de acompañamiento',
            self::CUBIERTOS => 'Cubiertos',
        };
    }

    /**
     * Obtiene las opciones para un campo Select.
     */
    public static function getSelectOptions(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }
        return $options;
    }

    /**
     * Obtiene todos los valores del enum como array.
     */
    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}