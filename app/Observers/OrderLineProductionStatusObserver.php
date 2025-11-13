<?php

namespace App\Observers;

use App\Jobs\MarkOrdersForProductionStatusUpdate;
use App\Models\OrderLine;
use Illuminate\Support\Facades\Log;

/**
 * Observer to detect changes in OrderLine that require
 * updating production status of the parent order.
 *
 * This observer:
 * - Detects when order lines are added (created)
 * - Detects when order line quantities change (updated)
 * - Detects when order lines are deleted (deleted)
 * - Marks the parent order as needing production status recalculation
 *
 * Example scenario:
 * 1. Order has Product A with 3 units, fully covered by OP #1 → FULLY_PRODUCED
 * 2. User updates Product A to 4 units → Observer marks order with needs_update = true
 * 3. User sees PARTIALLY_PRODUCED immediately (until command recalculates)
 * 4. Command runs: 4 units needed vs 3 covered → PARTIALLY_PRODUCED confirmed
 */
class OrderLineProductionStatusObserver
{
    /**
     * Handle the OrderLine "created" event.
     * When a new product is added to an order, mark the order for recalculation.
     */
    public function created(OrderLine $orderLine): void
    {
        // Skip observer execution during bulk imports
        if (OrderLine::$importMode) {
            Log::info('OrderLineProductionStatusObserver: SKIPPED (import mode)', [
                'order_line_id' => $orderLine->id,
                'order_id' => $orderLine->order_id,
            ]);
            return;
        }

        Log::info('OrderLineProductionStatusObserver: created', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
            'product_id' => $orderLine->product_id,
            'quantity' => $orderLine->quantity,
        ]);

        $this->markOrderForUpdate($orderLine->order_id);
    }

    /**
     * Handle the OrderLine "updated" event.
     * When quantity changes, mark the order for recalculation.
     */
    public function updated(OrderLine $orderLine): void
    {
        // Skip observer execution during bulk imports
        if (OrderLine::$importMode) {
            Log::info('OrderLineProductionStatusObserver: SKIPPED (import mode)', [
                'order_line_id' => $orderLine->id,
                'order_id' => $orderLine->order_id,
            ]);
            return;
        }

        // Only mark if quantity changed
        if (!$orderLine->wasChanged('quantity') && !$orderLine->wasChanged('partially_scheduled')) {
            return;
        }

        Log::info('OrderLineProductionStatusObserver: updated', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
            'product_id' => $orderLine->product_id,
            'old_quantity' => $orderLine->getOriginal('quantity'),
            'new_quantity' => $orderLine->quantity,
            'old_partially_scheduled' => $orderLine->getOriginal('partially_scheduled'),
            'new_partially_scheduled' => $orderLine->partially_scheduled,
        ]);

        $this->markOrderForUpdate($orderLine->order_id);
    }

    /**
     * Dispatch job to mark the order as needing production status update.
     */
    protected function markOrderForUpdate(int $orderId): void
    {
        Log::info('OrderLineProductionStatusObserver: Dispatching job', [
            'order_id' => $orderId,
        ]);

        // Dispatch job to avoid saturating the server
        MarkOrdersForProductionStatusUpdate::dispatch([$orderId]);
    }
}
