<?php

namespace App\Classes;

class PriceFormatter
{
    public static function format($price)
    {
        return '$'.number_format($price / 100, 2, ',', '.');
    }

    /**
     * Format price as rounded integer (no decimals)
     * Example: 148750 cents -> "$1.488"
     */
    public static function formatRounded($price)
    {
        return '$'.number_format(round($price / 100), 0, ',', '.');
    }
}