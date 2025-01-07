<?php

namespace App\CLasses;

class PriceFormatter
{
    public static function format($price)
    {
        return '$'.number_format($price / 100, 2, ',', '.');
    }
}