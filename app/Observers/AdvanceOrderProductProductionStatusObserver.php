<?php

namespace App\Observers;

use App\Jobs\MarkOrdersForProductionStatusUpdate;
use App\Models\AdvanceOrderProduct;
use App\Repositories\AdvanceOrderRepository;
use Illuminate\Support\Facades\Log;

/**
 * Observer to detect changes in AdvanceOrderProduct that require
 * updating production status of related orders.
 *
 * This observer:
 * - Detects when products are added to an OP (created)
 * - Detects when products are removed from an OP (deleted)
 * - Detects when product quantities change (updated)
 * - Finds all related orders via advance_order_order_lines pivot table
 * - Dispatches a queued job to mark those orders with production_status_needs_update = true
 */
class AdvanceOrderProductProductionStatusObserver
{
    protected AdvanceOrderRepository $repository;

    public function __construct(AdvanceOrderRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Handle the AdvanceOrderProduct "created" event.
     * When a product is added to an OP, mark all related orders.
     */
    public function created(AdvanceOrderProduct $advanceOrderProduct): void
    {
        Log::info('AdvanceOrderProductProductionStatusObserver: created', [
            'advance_order_product_id' => $advanceOrderProduct->id,
            'advance_order_id' => $advanceOrderProduct->advance_order_id,
            'product_id' => $advanceOrderProduct->product_id,
        ]);

        $this->markRelatedOrders($advanceOrderProduct->advance_order_id, $advanceOrderProduct->product_id);
    }

    /**
     * Handle the AdvanceOrderProduct "updated" event.
     * When a product quantity changes, mark all related orders.
     */
    public function updated(AdvanceOrderProduct $advanceOrderProduct): void
    {
        Log::info('AdvanceOrderProductProductionStatusObserver: updated', [
            'advance_order_product_id' => $advanceOrderProduct->id,
            'advance_order_id' => $advanceOrderProduct->advance_order_id,
            'product_id' => $advanceOrderProduct->product_id,
        ]);

        $this->markRelatedOrders($advanceOrderProduct->advance_order_id, $advanceOrderProduct->product_id);
    }

    /**
     * Handle the AdvanceOrderProduct "deleted" event.
     * When a product is removed from an OP, mark all related orders.
     */
    public function deleted(AdvanceOrderProduct $advanceOrderProduct): void
    {
        Log::info('AdvanceOrderProductProductionStatusObserver: deleted', [
            'advance_order_product_id' => $advanceOrderProduct->id,
            'advance_order_id' => $advanceOrderProduct->advance_order_id,
            'product_id' => $advanceOrderProduct->product_id,
        ]);

        $this->markRelatedOrders($advanceOrderProduct->advance_order_id, $advanceOrderProduct->product_id);
    }

    /**
     * Get all order IDs related to this AdvanceOrder and product via repository
     * and dispatch a job to mark them as needing update.
     */
    protected function markRelatedOrders(int $advanceOrderId, int $productId): void
    {
        $orderIds = $this->repository->getRelatedOrderIdsByProduct($advanceOrderId, $productId);

        if (empty($orderIds)) {
            Log::info('AdvanceOrderProductProductionStatusObserver: No related orders found', [
                'advance_order_id' => $advanceOrderId,
                'product_id' => $productId,
            ]);
            return;
        }

        Log::info('AdvanceOrderProductProductionStatusObserver: Dispatching job', [
            'advance_order_id' => $advanceOrderId,
            'product_id' => $productId,
            'order_ids_count' => count($orderIds),
        ]);

        // Dispatch job to avoid saturating the server
        MarkOrdersForProductionStatusUpdate::dispatch($orderIds);
    }
}
