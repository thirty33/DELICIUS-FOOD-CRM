<?php

namespace App\Repositories;

use App\Models\AdvanceOrder;
use App\Models\AdvanceOrderProduct;
use Illuminate\Support\Collection;

class AdvanceOrderRepository
{
    /**
     * Get previous advance orders with overlapping dispatch date ranges
     *
     * This method finds previous production orders whose dispatch date ranges
     * overlap with the current order's range. It no longer requires identical
     * preparation dates or dispatch ranges.
     *
     * Overlapping scenarios:
     * 1. Previous order's initial date is within current range
     * 2. Previous order's final date is within current range
     * 3. Previous order's range completely contains current range
     *
     * @param AdvanceOrder $currentAdvanceOrder
     * @return Collection Collection of AdvanceOrder models
     */
    public function getPreviousAdvanceOrdersWithSameDates(AdvanceOrder $currentAdvanceOrder): Collection
    {
        return AdvanceOrder::where('id', '<', $currentAdvanceOrder->id)
            ->where(function($query) use ($currentAdvanceOrder) {
                // Scenario 1: Previous order's initial_dispatch_date falls within current range
                $query->whereBetween('initial_dispatch_date', [
                    $currentAdvanceOrder->initial_dispatch_date,
                    $currentAdvanceOrder->final_dispatch_date
                ])
                // Scenario 2: Previous order's final_dispatch_date falls within current range
                ->orWhereBetween('final_dispatch_date', [
                    $currentAdvanceOrder->initial_dispatch_date,
                    $currentAdvanceOrder->final_dispatch_date
                ])
                // Scenario 3: Previous order's range completely contains current range
                ->orWhere(function($q) use ($currentAdvanceOrder) {
                    $q->where('initial_dispatch_date', '<=', $currentAdvanceOrder->initial_dispatch_date)
                      ->where('final_dispatch_date', '>=', $currentAdvanceOrder->final_dispatch_date);
                });
            })
            ->orderBy('id', 'desc')
            ->get();
    }

    /**
     * Get max ordered quantity for a product from previous advance orders
     *
     * @param int $productId
     * @param Collection $previousAdvanceOrders
     * @return int
     */
    public function getMaxOrderedQuantityForProduct(int $productId, Collection $previousAdvanceOrders): int
    {
        if ($previousAdvanceOrders->isEmpty()) {
            return 0;
        }

        $advanceOrderIds = $previousAdvanceOrders->pluck('id')->toArray();

        $maxQuantity = AdvanceOrderProduct::whereIn('advance_order_id', $advanceOrderIds)
            ->where('product_id', $productId)
            ->max('ordered_quantity');

        return $maxQuantity ?? 0;
    }

    /**
     * Get all advance orders with same dates (preparation and dispatch range)
     *
     * @param AdvanceOrder $currentAdvanceOrder
     * @return Collection Collection of AdvanceOrder models
     */
    public function getAdvanceOrdersWithSameDates(AdvanceOrder $currentAdvanceOrder): Collection
    {
        return AdvanceOrder::where('preparation_datetime', $currentAdvanceOrder->preparation_datetime)
            ->where('initial_dispatch_date', $currentAdvanceOrder->initial_dispatch_date)
            ->where('final_dispatch_date', $currentAdvanceOrder->final_dispatch_date)
            ->where('id', '!=', $currentAdvanceOrder->id)
            ->orderBy('id', 'asc')
            ->get();
    }

    /**
     * Check if advance order can be cancelled
     * Can cancel if: no other EXECUTED orders with same dates OR this is the last created EXECUTED order
     *
     * @param AdvanceOrder $advanceOrder
     * @return bool
     */
    public function canCancelAdvanceOrder(AdvanceOrder $advanceOrder): bool
    {
        $ordersWithSameDates = $this->getAdvanceOrdersWithSameDates($advanceOrder);

        // Filter only EXECUTED orders
        $executedOrders = $ordersWithSameDates->filter(function ($order) {
            return $order->status === \App\Enums\AdvanceOrderStatus::EXECUTED;
        });

        // If no other EXECUTED orders with same dates, can cancel
        if ($executedOrders->isEmpty()) {
            return true;
        }

        // Check if this is the last created EXECUTED order (highest ID)
        $maxId = $executedOrders->max('id');
        return $advanceOrder->id > $maxId;
    }
}
