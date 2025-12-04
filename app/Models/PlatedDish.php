<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlatedDish extends Model
{
    protected $table = 'plated_dishes';

    protected $fillable = [
        'product_id',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Get the product that owns this plated dish
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Get all ingredients for this plated dish
     */
    public function ingredients(): HasMany
    {
        return $this->hasMany(PlatedDishIngredient::class)->orderBy('order_index');
    }

    /**
     * Get active ingredients for this plated dish
     */
    public function activeIngredients(): HasMany
    {
        return $this->ingredients()->where('is_optional', false);
    }

    /**
     * Get optional ingredients for this plated dish
     */
    public function optionalIngredients(): HasMany
    {
        return $this->ingredients()->where('is_optional', true);
    }

    /**
     * Check if plated dish is active
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Get total number of ingredients
     */
    public function getIngredientsCount(): int
    {
        return $this->ingredients()->count();
    }
}