<?php

namespace App\Observers;

use App\Enums\OrderProductionStatus;
use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * Observer to handle Order deletion and trigger order_line observers.
 *
 * PROBLEM:
 * When an Order is deleted, the database CASCADE deletes order_lines
 * without triggering Laravel observers. This means:
 * - No surplus warehouse transactions are created
 * - ordered_quantity_new is not recalculated in advance_order_products
 *
 * SOLUTION:
 * This observer intercepts the deleting event and manually deletes each
 * order_line BEFORE the cascade, triggering their observers.
 *
 * OPTIMIZATION:
 * Only processes orders that have been produced (FULLY_PRODUCED or PARTIALLY_PRODUCED).
 * Orders with NOT_PRODUCED status don't need surplus transactions.
 */
class OrderDeletionObserver
{
    /**
     * Handle the Order "deleting" event.
     *
     * Manually delete each order_line to trigger their observers,
     * which will create surplus transactions and recalculate advance_order_products.
     */
    public function deleting(Order $order): void
    {
        // Only process if order has been produced
        if (!$this->orderHasProduction($order)) {
            Log::info('OrderDeletionObserver::deleting: Skipping (no production)', [
                'order_id' => $order->id,
                'production_status' => $order->production_status,
            ]);
            return;
        }

        $orderLinesCount = $order->orderLines()->count();

        Log::info('OrderDeletionObserver::deleting: Processing order_lines before cascade', [
            'order_id' => $order->id,
            'production_status' => $order->production_status,
            'order_lines_count' => $orderLinesCount,
        ]);

        // Delete each order_line individually to trigger their observers
        // This creates surplus warehouse transactions for produced items
        // and recalculates ordered_quantity_new in advance_order_products
        foreach ($order->orderLines as $orderLine) {
            Log::info('OrderDeletionObserver::deleting: Deleting order_line', [
                'order_id' => $order->id,
                'order_line_id' => $orderLine->id,
                'product_id' => $orderLine->product_id,
                'quantity' => $orderLine->quantity,
            ]);

            $orderLine->delete();
        }

        Log::info('OrderDeletionObserver::deleting: Completed', [
            'order_id' => $order->id,
            'order_lines_deleted' => $orderLinesCount,
        ]);
    }

    /**
     * Check if the order has been produced (fully or partially).
     *
     * Only orders with production need surplus transactions when deleted.
     * Orders with NOT_PRODUCED status can be deleted without processing.
     */
    protected function orderHasProduction(Order $order): bool
    {
        $status = $order->production_status;

        return $status === OrderProductionStatus::FULLY_PRODUCED->value
            || $status === OrderProductionStatus::PARTIALLY_PRODUCED->value;
    }
}
