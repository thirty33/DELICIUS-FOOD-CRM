<?php

namespace App\Repositories;

use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AdvanceOrderProductRepository
{
    protected WarehouseRepository $warehouseRepository;

    public function __construct(?WarehouseRepository $warehouseRepository = null)
    {
        $this->warehouseRepository = $warehouseRepository ?? app(WarehouseRepository::class);
    }

    /**
     * Create an AdvanceOrderProduct with proper total_to_produce calculation
     *
     * This method ensures that total_to_produce is calculated correctly by:
     * 1. Considering current warehouse stock (initial_inventory)
     * 2. Using the formula: MAX(0, quantity_to_produce - initial_inventory)
     * 3. Where quantity_to_produce is ordered_quantity_new + manual_quantity
     *
     * IMPORTANT: This method saves quietly (without triggering Observer events)
     * to prevent premature pivot synchronization. The calculation is done
     * manually here to ensure inventory is considered.
     *
     * @param int $advanceOrderId The advance order ID
     * @param int $productId The product ID
     * @param int $orderedQuantity Total quantity from all orders
     * @param int $orderedQuantityNew New quantity (not covered by previous OPs)
     * @param int $manualQuantity Manual adjustment ("Adelantar" field)
     * @return AdvanceOrderProduct The created instance
     */
    public function createAdvanceOrderProduct(
        int $advanceOrderId,
        int $productId,
        int $orderedQuantity,
        int $orderedQuantityNew,
        int $manualQuantity = 0
    ): AdvanceOrderProduct {
        // Get current stock in default warehouse
        $defaultWarehouse = $this->warehouseRepository->getDefaultWarehouse();
        $initialInventory = 0;

        if ($defaultWarehouse) {
            $initialInventory = $this->warehouseRepository->getProductStockInWarehouse(
                $productId,
                $defaultWarehouse->id
            );
        } else {
            Log::warning('No default warehouse found for AdvanceOrderProduct creation', [
                'advance_order_id' => $advanceOrderId,
                'product_id' => $productId,
            ]);
        }

        // Calculate total_to_produce using correct formula with inventory
        // Formula:
        // - If manual_quantity > 0: MAX(0, manual_quantity - initial_inventory)
        // - Else: MAX(0, ordered_quantity_new - initial_inventory)
        $totalToProduce = 0;
        $formula = '';

        if ($manualQuantity > 0) {
            $totalToProduce = max(0, $manualQuantity - $initialInventory);
            $formula = "MAX(0, {$manualQuantity} - {$initialInventory}) = {$totalToProduce}";
        } else {
            $totalToProduce = max(0, $orderedQuantityNew - $initialInventory);
            $formula = "MAX(0, {$orderedQuantityNew} - {$initialInventory}) = {$totalToProduce}";
        }

        Log::debug('AdvanceOrderProductRepository: creating with calculated total_to_produce', [
            'advance_order_id' => $advanceOrderId,
            'product_id' => $productId,
            'warehouse_id' => $defaultWarehouse?->id,
            'initial_inventory' => $initialInventory,
            'ordered_quantity' => $orderedQuantity,
            'ordered_quantity_new' => $orderedQuantityNew,
            'manual_quantity' => $manualQuantity,
            'formula_used' => $formula,
            'total_to_produce' => $totalToProduce,
        ]);

        // Create instance
        $advanceOrderProduct = new AdvanceOrderProduct([
            'advance_order_id' => $advanceOrderId,
            'product_id' => $productId,
            'ordered_quantity' => $orderedQuantity,
            'ordered_quantity_new' => $orderedQuantityNew,
            'quantity' => $manualQuantity,
            'total_to_produce' => $totalToProduce,
        ]);

        // Save without triggering Observer events to prevent premature pivot sync
        // The Observer will still work when products are added manually via Filament
        $advanceOrderProduct->saveQuietly();

        return $advanceOrderProduct;
    }

    /**
     * Update total_to_produce for an existing AdvanceOrderProduct
     * This recalculates based on current warehouse stock
     *
     * @param AdvanceOrderProduct $advanceOrderProduct
     * @return bool
     */
    public function recalculateTotalToProduce(AdvanceOrderProduct $advanceOrderProduct): bool
    {
        $totalToProduce = $advanceOrderProduct->calculateTotalToProduce();

        return $advanceOrderProduct->update([
            'total_to_produce' => $totalToProduce
        ]);
    }

    /**
     * Associate products to an advance order with default quantity of 0 and ordered quantities
     *
     * @param AdvanceOrder $advanceOrder
     * @param Collection $productsData Collection of arrays with 'product_id' and 'ordered_quantity'
     * @param AdvanceOrderRepository $advanceOrderRepository
     * @return void
     */
    public function associateProductsWithDefaultQuantity(
        AdvanceOrder $advanceOrder,
        Collection $productsData,
        AdvanceOrderRepository $advanceOrderRepository
    ): void {
        // Get previous advance orders with same dates
        $previousAdvanceOrders = $advanceOrderRepository->getPreviousAdvanceOrdersWithSameDates($advanceOrder);

        foreach ($productsData as $productData) {
            $currentOrderedQuantity = $productData['ordered_quantity'];
            $productId = $productData['product_id'];

            // Get max ordered quantity from previous advance orders for this product
            // (only considering orders in overlapping dispatch dates)
            $maxPreviousQuantity = $advanceOrderRepository->getMaxOrderedQuantityForProduct(
                $productId,
                $previousAdvanceOrders,
                $advanceOrder
            );

            // Calculate new ordered quantity
            $orderedQuantityNew = max(0, $currentOrderedQuantity - $maxPreviousQuantity);

            AdvanceOrderProduct::updateOrCreate(
                [
                    'advance_order_id' => $advanceOrder->id,
                    'product_id' => $productId,
                ],
                [
                    'quantity' => 0,
                    'ordered_quantity' => $currentOrderedQuantity,
                    'ordered_quantity_new' => $orderedQuantityNew,
                ]
            );
        }
    }

    /**
     * Get all products associated with an advance order
     *
     * @param AdvanceOrder $advanceOrder
     * @return Collection
     */
    public function getProductsForAdvanceOrder(AdvanceOrder $advanceOrder): Collection
    {
        return $advanceOrder->products()->get();
    }
}
