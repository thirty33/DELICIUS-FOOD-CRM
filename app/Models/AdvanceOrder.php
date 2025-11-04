<?php

namespace App\Models;

use App\Enums\AdvanceOrderStatus;
use App\Repositories\AdvanceOrderProductRepository;
use App\Repositories\AdvanceOrderRepository;
use App\Repositories\OrderRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdvanceOrder extends Model
{
    protected $fillable = [
        'initial_dispatch_date',
        'final_dispatch_date',
        'preparation_datetime',
        'description',
        'status',
        'use_products_in_orders',
    ];

    protected $casts = [
        'initial_dispatch_date' => 'date',
        'final_dispatch_date' => 'date',
        'preparation_datetime' => 'datetime',
        'use_products_in_orders' => 'boolean',
        'status' => AdvanceOrderStatus::class,
    ];

    protected static function booted(): void
    {
        static::updated(function (AdvanceOrder $advanceOrder) {
            // If use_products_in_orders was changed to false, remove all products
            if ($advanceOrder->isDirty('use_products_in_orders') && !$advanceOrder->use_products_in_orders) {
                $advanceOrder->advanceOrderProducts()->delete();
                return;
            }

            // Check if need to reload products:
            // 1. use_products_in_orders changed to true OR
            // 2. dispatch dates changed AND use_products_in_orders is true
            $shouldReloadProducts = (
                ($advanceOrder->isDirty('use_products_in_orders') && $advanceOrder->use_products_in_orders) ||
                (
                    $advanceOrder->use_products_in_orders &&
                    (
                        $advanceOrder->isDirty('initial_dispatch_date') ||
                        $advanceOrder->isDirty('final_dispatch_date')
                    )
                )
            );

            if ($shouldReloadProducts) {
                // Delete existing products before reloading
                $advanceOrder->advanceOrderProducts()->delete();

                $orderRepository = new OrderRepository();
                $advanceOrderProductRepository = new AdvanceOrderProductRepository();
                $advanceOrderRepository = new AdvanceOrderRepository();

                // Get products from orders in the date range with quantities
                $productsData = $orderRepository->getProductsFromOrdersInDateRange(
                    $advanceOrder->initial_dispatch_date->format('Y-m-d'),
                    $advanceOrder->final_dispatch_date->format('Y-m-d')
                );

                // Associate products with calculated ordered_quantity_new
                $advanceOrderProductRepository->associateProductsWithDefaultQuantity(
                    $advanceOrder,
                    $productsData,
                    $advanceOrderRepository
                );

                // Fire bulk load event to sync ALL orders/order_lines
                event(new \App\Events\AdvanceOrderProductsBulkLoaded($advanceOrder));
            }
        });
    }

    public function advanceOrderProducts(): HasMany
    {
        return $this->hasMany(AdvanceOrderProduct::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'advance_order_products')
            ->withPivot('quantity', 'ordered_quantity', 'ordered_quantity_new', 'total_to_produce')
            ->withTimestamps();
    }

    /**
     * Get the orders associated with this advance order through pivot table.
     */
    public function associatedOrders(): HasMany
    {
        return $this->hasMany(AdvanceOrderOrder::class, 'advance_order_id');
    }

    /**
     * Get the order lines associated with this advance order through pivot table.
     */
    public function associatedOrderLines(): HasMany
    {
        return $this->hasMany(AdvanceOrderOrderLine::class, 'advance_order_id');
    }

    /**
     * Get the production areas associated with this advance order.
     */
    public function productionAreas(): BelongsToMany
    {
        return $this->belongsToMany(ProductionArea::class, 'advance_order_production_area')
            ->withTimestamps();
    }
}
