<?php

namespace App\Enums;

enum TimePeriod: string
{
    case THIS_WEEK = 'this_week';
    case THIS_MONTH = 'this_month';
    case LAST_3_MONTHS = 'last_3_months';
    case LAST_6_MONTHS = 'last_6_months';
    case THIS_YEAR = 'this_year';

    /**
     * Obtiene las opciones para un select.
     *
     * @return array<string, string>
     */
    public static function getSelectOptions(): array
    {
        return [
            self::THIS_WEEK->value => 'Esta semana',
            self::THIS_MONTH->value => 'Este mes',
            self::LAST_3_MONTHS->value => 'Últimos 3 meses',
            self::LAST_6_MONTHS->value => 'Últimos 6 meses',
            self::THIS_YEAR->value => 'Este año',
        ];
    }

    /**
     * Obtiene los valores del enum como un array.
     *
     * @return array<string>
     */
    public static function getValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}