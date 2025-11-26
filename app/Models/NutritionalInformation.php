<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\NutritionalValueType;

class NutritionalInformation extends Model
{
    protected $table = 'nutritional_information';

    protected $fillable = [
        'product_id',
        'barcode',
        'ingredients',
        'allergens',
        'measure_unit',
        'net_weight',
        'gross_weight',
        'shelf_life_days',
        'generate_label',
        'high_sodium',
        'high_calories',
        'high_fat',
        'high_sugar',
    ];

    protected $casts = [
        'net_weight' => 'decimal:2',
        'gross_weight' => 'decimal:2',
        'shelf_life_days' => 'integer',
        'generate_label' => 'boolean',
        'high_sodium' => 'boolean',
        'high_calories' => 'boolean',
        'high_fat' => 'boolean',
        'high_sugar' => 'boolean',
    ];

    /**
     * Get the product that owns this nutritional information
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get all nutritional values for this nutritional information
     */
    public function nutritionalValues(): HasMany
    {
        return $this->hasMany(NutritionalValue::class);
    }

    /**
     * Get a specific nutritional value by type
     * For flag types, returns value from boolean fields
     * For nutritional types, returns value from nutritional_values table
     */
    public function getValue(NutritionalValueType $type): ?float
    {
        // If it's a flag type, get from boolean field
        if ($type->isFlag()) {
            return match ($type) {
                NutritionalValueType::HIGH_SODIUM => $this->high_sodium ? 1.0 : 0.0,
                NutritionalValueType::HIGH_CALORIES => $this->high_calories ? 1.0 : 0.0,
                NutritionalValueType::HIGH_FAT => $this->high_fat ? 1.0 : 0.0,
                NutritionalValueType::HIGH_SUGAR => $this->high_sugar ? 1.0 : 0.0,
                default => null,
            };
        }

        // For nutritional values, get from nutritional_values table
        $value = $this->nutritionalValues()
            ->where('type', $type->value)
            ->first();

        return $value ? (float) $value->value : null;
    }

    /**
     * Set a specific nutritional value
     */
    public function setValue(NutritionalValueType $type, float $value): NutritionalValue
    {
        return $this->nutritionalValues()->updateOrCreate(
            ['type' => $type->value],
            ['value' => $value]
        );
    }

    /**
     * Get all nutritional values as an associative array
     * [type => value]
     */
    public function getValuesArray(): array
    {
        return $this->nutritionalValues()
            ->get()
            ->pluck('value', 'type')
            ->toArray();
    }

    /**
     * Set multiple nutritional values at once
     * @param array $values ['type' => value, ...]
     */
    public function setValues(array $values): void
    {
        foreach ($values as $type => $value) {
            // Convert string type to enum if needed
            $typeEnum = $type instanceof NutritionalValueType
                ? $type
                : NutritionalValueType::from($type);

            $this->setValue($typeEnum, $value);
        }
    }

    /**
     * Check if product has high sodium content
     */
    public function hasHighSodium(): bool
    {
        return $this->high_sodium;
    }

    /**
     * Check if product has high calories content
     */
    public function hasHighCalories(): bool
    {
        return $this->high_calories;
    }

    /**
     * Check if product has high fat content
     */
    public function hasHighFat(): bool
    {
        return $this->high_fat;
    }

    /**
     * Check if product has high sugar content
     */
    public function hasHighSugar(): bool
    {
        return $this->high_sugar;
    }
}
