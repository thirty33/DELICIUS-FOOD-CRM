<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderRuleSubcategoryLimit Model
 *
 * Defines maximum product limits per subcategory for order rules.
 *
 * Example usage:
 * - "Maximum 2 ENTRADA products per order"
 * - "Maximum 1 PLATO DE FONDO product per order"
 *
 * Relationships:
 * - belongsTo OrderRule
 * - belongsTo Subcategory
 */
class OrderRuleSubcategoryLimit extends Model
{
    protected $fillable = [
        'order_rule_id',
        'subcategory_id',
        'max_products',
    ];

    protected $casts = [
        'max_products' => 'integer',
    ];

    /**
     * Get the order rule that owns this limit.
     */
    public function orderRule(): BelongsTo
    {
        return $this->belongsTo(OrderRule::class);
    }

    /**
     * Get the subcategory this limit applies to.
     */
    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Subcategory::class);
    }
}
