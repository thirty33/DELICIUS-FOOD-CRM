<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatedDishIngredient extends Model
{
    protected $table = 'plated_dish_ingredients';

    protected $fillable = [
        'plated_dish_id',
        'ingredient_name',
        'measure_unit',
        'quantity',
        'max_quantity_horeca',
        'order_index',
        'is_optional',
        'shelf_life',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
        'max_quantity_horeca' => 'decimal:3',
        'order_index' => 'integer',
        'is_optional' => 'boolean',
        'shelf_life' => 'integer',
    ];

    /**
     * Get the plated dish that owns this ingredient
     */
    public function platedDish(): BelongsTo
    {
        return $this->belongsTo(PlatedDish::class);
    }


    /**
     * Check if ingredient is optional
     */
    public function isOptional(): bool
    {
        return $this->is_optional;
    }

    /**
     * Check if ingredient has HORECA maximum quantity set
     */
    public function hasHorecaMaxQuantity(): bool
    {
        return $this->max_quantity_horeca !== null;
    }

    /**
     * Get quantity for HORECA (uses max_quantity_horeca if set, otherwise regular quantity)
     */
    public function getHorecaQuantity(): float
    {
        return $this->max_quantity_horeca ?? $this->quantity;
    }

    /**
     * Get formatted quantity with measure unit
     */
    public function getFormattedQuantity(): string
    {
        return number_format($this->quantity, 3) . ' ' . $this->measure_unit;
    }

    /**
     * Get formatted HORECA quantity with measure unit (if applicable)
     */
    public function getFormattedHorecaQuantity(): ?string
    {
        if (!$this->hasHorecaMaxQuantity()) {
            return null;
        }

        return number_format($this->max_quantity_horeca, 3) . ' ' . $this->measure_unit;
    }
}