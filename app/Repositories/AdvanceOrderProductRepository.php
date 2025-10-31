<?php

namespace App\Repositories;

use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
use Illuminate\Support\Collection;

class AdvanceOrderProductRepository
{
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
            $maxPreviousQuantity = $advanceOrderRepository->getMaxOrderedQuantityForProduct(
                $productId,
                $previousAdvanceOrders
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
