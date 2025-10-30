<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends Model
{
    protected $fillable = [
        'name',
        'code',
        'address',
        'active',
        'is_default',
    ];

    protected $casts = [
        'active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Get products associated with this warehouse
     */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'warehouse_product')
            ->withPivot('stock', 'unit_of_measure')
            ->withTimestamps();
    }

    /**
     * Get warehouse product records (pivot table records)
     */
    public function warehouseProducts(): HasMany
    {
        return $this->hasMany(WarehouseProduct::class);
    }

    /**
     * Get warehouse transactions
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(WarehouseTransaction::class);
    }

    /**
     * Get the stock for a specific product in this warehouse
     */
    public function getProductStock(int $productId): int
    {
        $product = $this->products()->where('product_id', $productId)->first();
        return $product ? $product->pivot->stock : 0;
    }

    /**
     * Update stock for a specific product in this warehouse
     */
    public function updateProductStock(int $productId, int $quantity): void
    {
        $this->products()->updateExistingPivot($productId, [
            'stock' => $quantity,
            'updated_at' => now(),
        ]);
    }
}
