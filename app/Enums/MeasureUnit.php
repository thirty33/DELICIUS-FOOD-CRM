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

    /**
     * Get the plural name in Spanish for bag descriptions
     *
     * Used in reports like "1 BOLSA DE 5 UNIDADES" or "2 BOLSAS DE 1000 GRAMOS"
     *
     * @param int|float $quantity The quantity to determine singular/plural
     * @return string The Spanish name (singular or plural based on quantity)
     */
    public function getPluralName(int|float $quantity = 2): string
    {
        $isSingular = abs($quantity) == 1;

        return match ($this) {
            self::GRAMS => $isSingular ? 'GRAMO' : 'GRAMOS',
            self::KILOGRAMS => $isSingular ? 'KILOGRAMO' : 'KILOGRAMOS',
            self::MILLILITERS => $isSingular ? 'MILILITRO' : 'MILILITROS',
            self::LITERS => $isSingular ? 'LITRO' : 'LITROS',
            self::UNIT => $isSingular ? 'UNIDAD' : 'UNIDADES',
            self::OUNCES => $isSingular ? 'ONZA' : 'ONZAS',
            self::POUNDS => $isSingular ? 'LIBRA' : 'LIBRAS',
        };
    }

    /**
     * Get the plural name from a string measure unit value
     *
     * Static helper for when you have the string value instead of the enum
     *
     * @param string $measureUnit The measure unit string (e.g., 'GR', 'UND')
     * @param int|float $quantity The quantity to determine singular/plural
     * @return string The Spanish name, or the original string if not found
     */
    public static function getPluralNameFromString(string $measureUnit, int|float $quantity = 2): string
    {
        $enum = self::tryFrom($measureUnit);

        if ($enum) {
            return $enum->getPluralName($quantity);
        }

        // Fallback: return the original string if not a valid enum value
        return $measureUnit;
    }
}