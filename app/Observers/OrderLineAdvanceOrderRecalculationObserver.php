<?php

namespace App\Observers;

use App\Jobs\RecalculateAdvanceOrderProductsJob;
use App\Models\AdvanceOrderOrderLine;
use App\Models\OrderLine;
use Illuminate\Support\Facades\Log;

/**
 * Observer to recalculate AdvanceOrderProducts when OrderLines are modified.
 *
 * This observer is SEPARATE from OrderLineProductionStatusObserver to maintain
 * single responsibility:
 * - OrderLineProductionStatusObserver: Handles production status updates and surplus transactions
 * - OrderLineAdvanceOrderRecalculationObserver: Handles advance_order_products.ordered_quantity_new recalculation
 *
 * When an OrderLine is modified or deleted:
 * 1. Find all AdvanceOrders that cover this OrderLine
 * 2. Dispatch a job to recalculate ordered_quantity_new for affected products
 *
 * This ensures the consolidated OP report always shows correct values:
 * TOTAL PEDIDOS = sum of individual company quantities
 */
class OrderLineAdvanceOrderRecalculationObserver
{
    /**
     * Handle the OrderLine "updated" event.
     * When quantity changes, dispatch recalculation job for affected OPs.
     *
     * NOTE: This SKIPS during import because:
     * 1. Import handles surplus check BEFORE recalculation (checkAndDispatchSurplusEvent)
     * 2. If we recalculate first, produced_quantity changes and surplus becomes 0
     * 3. Import triggers recalculation via AfterImport event AFTER all surplus checks
     */
    public function updated(OrderLine $orderLine): void
    {
        // Skip during import - import handles recalculation separately after surplus checks
        if (OrderLine::$importMode) {
            Log::info('OrderLineAdvanceOrderRecalculationObserver::updated: SKIPPED (import mode)', [
                'order_line_id' => $orderLine->id,
                'order_id' => $orderLine->order_id,
            ]);
            return;
        }

        Log::info('OrderLineAdvanceOrderRecalculationObserver::updated', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
        ]);

        // Only process if quantity changed
        if (!$orderLine->wasChanged('quantity')) {
            return;
        }

        $oldQuantity = (int) $orderLine->getOriginal('quantity');
        $newQuantity = (int) $orderLine->quantity;

        // Only recalculate when quantity DECREASES
        // When quantity increases, a new OP would cover the additional units
        // Recalculating on increase breaks the production proportion calculation
        if ($newQuantity >= $oldQuantity) {
            Log::info('OrderLineAdvanceOrderRecalculationObserver::updated: Quantity increased, skipping', [
                'order_line_id' => $orderLine->id,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $newQuantity,
            ]);
            return;
        }

        Log::info('OrderLineAdvanceOrderRecalculationObserver::updated: Quantity decreased', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
            'product_id' => $orderLine->product_id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $newQuantity,
        ]);

        $this->dispatchRecalculationJob($orderLine, $newQuantity);
    }

    /**
     * Handle the OrderLine "deleting" event (BEFORE delete).
     * When line is deleted, dispatch recalculation job with newQuantity = null.
     *
     * NOTE: This SKIPS during import because:
     * 1. If recalculation runs immediately, it changes ordered_quantity_new
     * 2. This affects surplus calculation for OTHER lines (they see wrong produced quantity)
     * 3. Import triggers recalculation via AfterImport AFTER all surplus checks complete
     */
    public function deleting(OrderLine $orderLine): void
    {
        // Skip during import - recalculation happens in AfterImport after all surplus checks
        if (OrderLine::$importMode) {
            Log::info('OrderLineAdvanceOrderRecalculationObserver::deleting: SKIPPED (import mode)', [
                'order_line_id' => $orderLine->id,
                'order_id' => $orderLine->order_id,
            ]);
            return;
        }

        Log::info('OrderLineAdvanceOrderRecalculationObserver::deleting', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
            'product_id' => $orderLine->product_id,
            'quantity' => $orderLine->quantity,
        ]);

        // Pass null as newQuantity to indicate deletion
        $this->dispatchRecalculationJob($orderLine, null);
    }

    /**
     * Dispatch the recalculation job for affected AdvanceOrders.
     *
     * IMPORTANT: We capture the advance_order_ids HERE (before the job runs)
     * because when the job executes asynchronously, the pivots may have been
     * deleted already (especially when deleting orders).
     *
     * @param OrderLine $orderLine The order line that was modified/deleted
     * @param int|null $newQuantity The new quantity (null if line was deleted)
     */
    protected function dispatchRecalculationJob(OrderLine $orderLine, ?int $newQuantity): void
    {
        // Capture advance_order_ids NOW while the pivots still exist
        $advanceOrderIds = AdvanceOrderOrderLine::where('order_line_id', $orderLine->id)
            ->pluck('advance_order_id')
            ->unique()
            ->toArray();

        if (empty($advanceOrderIds)) {
            Log::info('OrderLineAdvanceOrderRecalculationObserver: No OPs found, skipping', [
                'order_line_id' => $orderLine->id,
            ]);
            return;
        }

        Log::info('OrderLineAdvanceOrderRecalculationObserver: Dispatching recalculation job', [
            'order_line_id' => $orderLine->id,
            'order_id' => $orderLine->order_id,
            'product_id' => $orderLine->product_id,
            'new_quantity' => $newQuantity,
            'advance_order_ids' => $advanceOrderIds,
        ]);

        RecalculateAdvanceOrderProductsJob::dispatch(
            $orderLine->id,
            $orderLine->product_id,
            $orderLine->order_id,
            $newQuantity,
            $advanceOrderIds
        );
    }
}