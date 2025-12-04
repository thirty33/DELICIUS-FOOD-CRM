<?php

namespace App\Enums;

enum MeasureUnit: string
{
    case GRAMS = 'GR';
    case KILOGRAMS = 'KG';
    case MILLILITERS = 'ML';
    case LITERS = 'L';
    case UNIT = 'UND';
    case OUNCES = 'OZ';
    case POUNDS = 'LB';

    public function label(): string
    {
        return match ($this) {
            self::GRAMS => 'Gramos (GR)',
            self::KILOGRAMS => 'Kilogramos (KG)',
            self::MILLILITERS => 'Mililitros (ML)',
            self::LITERS => 'Litros (L)',
            self::UNIT => 'Unidad (UND)',
            self::OUNCES => 'Onzas (OZ)',
            self::POUNDS => 'Libras (LB)',
        };
    }

    public static function options(): array
    {
        return array_reduce(self::cases(), function ($carry, $case) {
            $carry[$case->value] = $case->label();
            return $carry;
        }, []);
    }

    /**
     * Map Excel values to system values
     *
     * This method normalizes various Excel input formats to standardized system values
     *
     * @param string|null $excelValue The value from Excel
     * @return string|null The normalized system value
     */
    public static function mapFromExcel(?string $excelValue): ?string
    {
        if ($excelValue === null || trim($excelValue) === '') {
            return null;
        }

        $normalized = strtoupper(trim($excelValue));

        // Mapping table: Excel value => System enum value
        $mapping = [
            // Grams variants
            'GR' => self::GRAMS->value,
            'GRAMOS' => self::GRAMS->value,
            'GRAMO' => self::GRAMS->value,
            'G' => self::GRAMS->value,

            // Kilograms variants
            'KG' => self::KILOGRAMS->value,
            'KILOGRAMOS' => self::KILOGRAMS->value,
            'KILOGRAMO' => self::KILOGRAMS->value,
            'KILO' => self::KILOGRAMS->value,
            'KILOS' => self::KILOGRAMS->value,

            // Milliliters variants
            'ML' => self::MILLILITERS->value,
            'MILILITROS' => self::MILLILITERS->value,
            'MILILITRO' => self::MILLILITERS->value,

            // Liters variants
            'L' => self::LITERS->value,
            'LITROS' => self::LITERS->value,
            'LITRO' => self::LITERS->value,
            'LT' => self::LITERS->value,

            // Units variants
            'UND' => self::UNIT->value,
            'UNIDAD' => self::UNIT->value,
            'UNIDADES' => self::UNIT->value,
            'U' => self::UNIT->value,

            // Ounces variants
            'OZ' => self::OUNCES->value,
            'ONZA' => self::OUNCES->value,
            'ONZAS' => self::OUNCES->value,

            // Pounds variants
            'LB' => self::POUNDS->value,
            'LIBRA' => self::POUNDS->value,
            'LIBRAS' => self::POUNDS->value,
        ];

        return $mapping[$normalized] ?? $normalized;
    }

    /**
     * Get all valid system values for validation
     *
     * @return array
     */
    public static function getValidValues(): array
    {
        return array_map(fn($case) => $case->value, self::cases());
    }

    /**
     * Get validation rule string for Laravel validation
     *
     * @return string
     */
    public static function getValidationRule(): string
    {
        return 'in:' . implode(',', self::getValidValues());
    }

    /**
     * Check if this is a weight unit
     *
     * @return bool
     */
    public function isWeight(): bool
    {
        return in_array($this, [
            self::GRAMS,
            self::KILOGRAMS,
            self::OUNCES,
            self::POUNDS,
        ]);
    }

    /**
     * Check if this is a volume unit
     *
     * @return bool
     */
    public function isVolume(): bool
    {
        return in_array($this, [
            self::MILLILITERS,
            self::LITERS,
        ]);
    }

    /**
     * Check if this is a count unit
     *
     * @return bool
     */
    public function isCount(): bool
    {
        return $this === self::UNIT;
    }
}