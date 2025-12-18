<?php

namespace App\Observers;

use App\Events\OrderLineQuantityReducedBelowProduced;
use App\Jobs\MarkOrdersForProductionStatusUpdate;
use App\Models\OrderLine;
use App\Repositories\OrderRepository;
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
 * - Dispatches surplus event when quantity is reduced below produced amount
 *
 * Example scenario:
 * 1. Order has Product A with 3 units, fully covered by OP #1 → FULLY_PRODUCED
 * 2. User updates Product A to 4 units → Observer marks order with needs_update = true
 * 3. User sees PARTIALLY_PRODUCED immediately (until command recalculates)
 * 4. Command runs: 4 units needed vs 3 covered → PARTIALLY_PRODUCED confirmed
 *
 * Surplus scenario:
 * 1. Order line has qty=10, produced=10
 * 2. User reduces qty to 8
 * 3. Observer calculates surplus = max(0, 10 - 8) = 2
 * 4. Observer dispatches OrderLineQuantityReducedBelowProduced event
 * 5. Listener creates warehouse transaction to add 2 units to inventory
 */
class OrderLineProductionStatusObserver
{
    protected OrderRepository $orderRepository;

    public function __construct(OrderRepository $orderRepository)
    {
        $this->orderRepository = $orderRepository;
    }
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
     * If quantity is reduced below produced amount, dispatch surplus event.
     */
    public function updated(OrderLine $orderLine): void
    {
        Log::info('=== OBSERVER::updated START ===', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
        ]);

        // Skip observer execution during bulk imports
        if (OrderLine::$importMode) {
            Log::info('OrderLineProductionStatusObserver: SKIPPED (import mode)', [
                'order_line_id' => $orderLine->id,
                'order_id' => $orderLine->order_id,
            ]);
            return;
        }

        // Only process if quantity changed
        $quantityChanged = $orderLine->wasChanged('quantity');
        $partiallyScheduledChanged = $orderLine->wasChanged('partially_scheduled');

        if (!$quantityChanged && !$partiallyScheduledChanged) {
            return;
        }

        $oldQuantity = (int) $orderLine->getOriginal('quantity');
        $newQuantity = (int) $orderLine->quantity;

        Log::info('OrderLineProductionStatusObserver: updated', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
            'product_id' => $orderLine->product_id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'old_partially_scheduled' => $orderLine->getOriginal('partially_scheduled'),
            'new_partially_scheduled' => $orderLine->partially_scheduled,
        ]);

        // Check for surplus when quantity is reduced
        if ($quantityChanged && $newQuantity < $oldQuantity) {
            Log::info('=== CALLING checkAndDispatchSurplusEvent ===');
            $this->checkAndDispatchSurplusEvent($orderLine, $oldQuantity, $newQuantity);
        }

        $this->markOrderForUpdate($orderLine->order_id);
        Log::info('=== OBSERVER::updated END ===');
    }

    /**
     * Check if a surplus event should be dispatched when quantity is reduced.
     * Surplus = max(0, producedQuantity - newQuantity)
     */
    protected function checkAndDispatchSurplusEvent(OrderLine $orderLine, int $oldQuantity, int $newQuantity): void
    {
        // Get the amount actually produced for this product in this order
        $producedQuantity = $this->orderRepository->getTotalProducedForProduct(
            $orderLine->order_id,
            $orderLine->product_id
        );

        // Calculate surplus: what was produced minus what is now needed
        $surplus = max(0, $producedQuantity - $newQuantity);

        Log::info('OrderLineProductionStatusObserver: Checking surplus', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
            'product_id' => $orderLine->product_id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
            'produced_quantity' => $producedQuantity,
            'surplus' => $surplus,
        ]);

        if ($surplus > 0) {
            Log::info('OrderLineProductionStatusObserver: Dispatching surplus event', [
                'order_line_id' => $orderLine->id,
                'surplus' => $surplus,
            ]);

            event(new OrderLineQuantityReducedBelowProduced(
                $orderLine,
                $oldQuantity,
                $newQuantity,
                (int) $producedQuantity,
                $surplus,
                auth()->id()
            ));
        }
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
