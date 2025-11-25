<?php

namespace App\Enums;

enum NutritionalValueType: string
{
    // Energy and Macronutrients
    case CALORIES = 'calories';
    case PROTEIN = 'protein';
    case FAT_TOTAL = 'fat_total';
    case FAT_SATURATED = 'fat_saturated';
    case FAT_MONOUNSATURATED = 'fat_monounsaturated';
    case FAT_POLYUNSATURATED = 'fat_polyunsaturated';
    case FAT_TRANS = 'fat_trans';
    case CHOLESTEROL = 'cholesterol';
    case CARBOHYDRATE = 'carbohydrate';
    case FIBER = 'fiber';
    case SUGAR = 'sugar';
    case SODIUM = 'sodium';

    // High Content Flags (0 or 1)
    case HIGH_SODIUM = 'high_sodium';
    case HIGH_CALORIES = 'high_calories';
    case HIGH_FAT = 'high_fat';
    case HIGH_SUGAR = 'high_sugar';

    /**
     * Get label for display
     */
    public function label(): string
    {
        return match($this) {
            self::CALORIES => 'Calorías',
            self::PROTEIN => 'Proteína',
            self::FAT_TOTAL => 'Grasa Total',
            self::FAT_SATURATED => 'Grasa Saturada',
            self::FAT_MONOUNSATURATED => 'Grasa Monoinsaturada',
            self::FAT_POLYUNSATURATED => 'Grasa Poliinsaturada',
            self::FAT_TRANS => 'Grasa Trans',
            self::CHOLESTEROL => 'Colesterol',
            self::CARBOHYDRATE => 'Carbohidrato',
            self::FIBER => 'Fibra',
            self::SUGAR => 'Azúcar',
            self::SODIUM => 'Sodio',
            self::HIGH_SODIUM => 'Alto en Sodio',
            self::HIGH_CALORIES => 'Alto en Calorías',
            self::HIGH_FAT => 'Alto en Grasas',
            self::HIGH_SUGAR => 'Alto en Azúcares',
        };
    }

    /**
     * Get unit of measurement
     */
    public function unit(): string
    {
        return match($this) {
            self::CALORIES => 'kcal',
            self::PROTEIN, self::FAT_TOTAL, self::FAT_SATURATED,
            self::FAT_MONOUNSATURATED, self::FAT_POLYUNSATURATED,
            self::FAT_TRANS, self::CARBOHYDRATE, self::FIBER, self::SUGAR => 'g',
            self::CHOLESTEROL, self::SODIUM => 'mg',
            self::HIGH_SODIUM, self::HIGH_CALORIES, self::HIGH_FAT, self::HIGH_SUGAR => '',
        };
    }

    /**
     * Check if this is a flag type (0 or 1)
     */
    public function isFlag(): bool
    {
        return in_array($this, [
            self::HIGH_SODIUM,
            self::HIGH_CALORIES,
            self::HIGH_FAT,
            self::HIGH_SUGAR,
        ]);
    }

    /**
     * Get all nutritional value types (excluding flags)
     */
    public static function nutritionalTypes(): array
    {
        return [
            self::CALORIES,
            self::PROTEIN,
            self::FAT_TOTAL,
            self::FAT_SATURATED,
            self::FAT_MONOUNSATURATED,
            self::FAT_POLYUNSATURATED,
            self::FAT_TRANS,
            self::CHOLESTEROL,
            self::CARBOHYDRATE,
            self::FIBER,
            self::SUGAR,
            self::SODIUM,
        ];
    }

    /**
     * Get all flag types
     */
    public static function flagTypes(): array
    {
        return [
            self::HIGH_SODIUM,
            self::HIGH_CALORIES,
            self::HIGH_FAT,
            self::HIGH_SUGAR,
        ];
    }
}
