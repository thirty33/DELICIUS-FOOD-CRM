<?php

namespace App\Enums;

enum MeasureUnit: string
{
    case GRAMS = 'GR';
    case KILOGRAMS = 'KG';
    case UNIT = 'UND';

    public function label(): string
    {
        return match ($this) {
            self::GRAMS => 'Gramos (GR)',
            self::KILOGRAMS => 'Kilogramos (KG)',
            self::UNIT => 'Unidad (UND)',
        };
    }

    public static function options(): array
    {
        return array_reduce(self::cases(), function ($carry, $case) {
            $carry[$case->value] = $case->label();
            return $carry;
        }, []);
    }
}