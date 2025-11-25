<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Enums\NutritionalValueType;

class NutritionalValue extends Model
{
    protected $fillable = [
        'nutritional_information_id',
        'type',
        'value',
    ];

    protected $casts = [
        'value' => 'decimal:3',
        'type' => NutritionalValueType::class,
    ];

    /**
     * Get the nutritional information that owns this value
     */
    public function nutritionalInformation(): BelongsTo
    {
        return $this->belongsTo(NutritionalInformation::class);
    }

    /**
     * Get the label for this nutritional value type
     */
    public function getLabel(): string
    {
        return $this->type->label();
    }

    /**
     * Get the unit of measurement for this nutritional value type
     */
    public function getUnit(): string
    {
        return $this->type->unit();
    }

    /**
     * Check if this is a flag type (0 or 1)
     */
    public function isFlag(): bool
    {
        return $this->type->isFlag();
    }

    /**
     * Get formatted value with unit
     */
    public function getFormattedValue(): string
    {
        $value = $this->isFlag() ? (int) $this->value : $this->value;
        $unit = $this->getUnit();

        return $unit ? "{$value} {$unit}" : (string) $value;
    }
}
