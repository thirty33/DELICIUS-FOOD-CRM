<?php

namespace App\Repositories;

use App\Models\Warehouse;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class WarehouseRepository
{
    /**
     * Get the default warehouse
     */
    public function getDefaultWarehouse(): ?Warehouse
    {
        return Warehouse::where('is_default', true)
            ->where('active', true)
            ->first();
    }

    /**
     * Associate a product with the default warehouse
     */
    public function associateProductToDefaultWarehouse(Product $product, int $initialStock = 0, string $unitOfMeasure = 'UND'): void
    {
        $defaultWarehouse = $this->getDefaultWarehouse();

        if ($defaultWarehouse) {
            $this->associateProductToWarehouse($product, $defaultWarehouse, $initialStock, $unitOfMeasure);
        }
    }

    /**
     * Associate a product with a specific warehouse
     */
    public function associateProductToWarehouse(
        Product $product,
        Warehouse $warehouse,
        int $initialStock = 0,
        string $unitOfMeasure = 'UND'
    ): void {
        // Check if association already exists
        $exists = DB::table('warehouse_product')
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->exists();

        if (!$exists) {
            $warehouse->products()->attach($product->id, [
                'stock' => $initialStock,
                'unit_of_measure' => $unitOfMeasure,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Get stock of a product in a specific warehouse
     */
    public function getProductStockInWarehouse(int $productId, int $warehouseId): int
    {
        $pivot = DB::table('warehouse_product')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->first();

        return $pivot ? $pivot->stock : 0;
    }

    /**
     * Update stock of a product in a specific warehouse
     */
    public function updateProductStockInWarehouse(
        int $productId,
        int $warehouseId,
        int $newStock
    ): bool {
        return DB::table('warehouse_product')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->update([
                'stock' => $newStock,
                'updated_at' => now(),
            ]) > 0;
    }

    /**
     * Increment stock of a product in a specific warehouse
     */
    public function incrementProductStock(
        int $productId,
        int $warehouseId,
        int $quantity
    ): void {
        DB::table('warehouse_product')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->increment('stock', $quantity, ['updated_at' => now()]);
    }

    /**
     * Decrement stock of a product in a specific warehouse
     */
    public function decrementProductStock(
        int $productId,
        int $warehouseId,
        int $quantity
    ): void {
        DB::table('warehouse_product')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $productId)
            ->decrement('stock', $quantity, ['updated_at' => now()]);
    }

    /**
     * Get all products in a warehouse with their stock
     */
    public function getWarehouseProducts(int $warehouseId)
    {
        return DB::table('warehouse_product')
            ->join('products', 'warehouse_product.product_id', '=', 'products.id')
            ->where('warehouse_product.warehouse_id', $warehouseId)
            ->select(
                'products.*',
                'warehouse_product.stock',
                'warehouse_product.unit_of_measure',
                'warehouse_product.updated_at as stock_updated_at'
            )
            ->get();
    }

    /**
     * Get total stock across all warehouses for a product
     */
    public function getTotalStockForProduct(int $productId): int
    {
        return DB::table('warehouse_product')
            ->where('product_id', $productId)
            ->sum('stock');
    }

    /**
     * Check if a product exists in any warehouse
     */
    public function productExistsInWarehouses(int $productId): bool
    {
        return DB::table('warehouse_product')
            ->where('product_id', $productId)
            ->exists();
    }

    /**
     * Get all warehouses where a product exists
     */
    public function getWarehousesForProduct(int $productId)
    {
        return DB::table('warehouse_product')
            ->join('warehouses', 'warehouse_product.warehouse_id', '=', 'warehouses.id')
            ->where('warehouse_product.product_id', $productId)
            ->select(
                'warehouses.*',
                'warehouse_product.stock',
                'warehouse_product.unit_of_measure'
            )
            ->get();
    }

    /**
     * Transfer stock from one warehouse to another
     */
    public function transferStock(
        int $productId,
        int $fromWarehouseId,
        int $toWarehouseId,
        int $quantity
    ): bool {
        return DB::transaction(function () use ($productId, $fromWarehouseId, $toWarehouseId, $quantity) {
            // Check if origin warehouse has enough stock
            $currentStock = $this->getProductStockInWarehouse($productId, $fromWarehouseId);

            if ($currentStock < $quantity) {
                return false;
            }

            // Decrement from origin
            $this->decrementProductStock($productId, $fromWarehouseId, $quantity);

            // Increment in destination
            $this->incrementProductStock($productId, $toWarehouseId, $quantity);

            return true;
        });
    }
}