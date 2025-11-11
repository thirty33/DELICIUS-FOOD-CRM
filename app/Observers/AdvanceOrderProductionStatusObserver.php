<?php

namespace App\Observers;

use App\Jobs\MarkOrdersForProductionStatusUpdate;
use App\Models\AdvanceOrder;
use App\Repositories\AdvanceOrderRepository;
use Illuminate\Support\Facades\Log;

/**
 * Observer to detect changes in AdvanceOrder that require
 * updating production status of related orders.
 *
 * This observer:
 * - Detects status changes (PENDING -> EXECUTED, EXECUTED -> CANCELLED, etc.)
 * - Detects date changes (initial_dispatch_date, final_dispatch_date)
 * - Finds all related orders via advance_order_orders pivot table
 * - Dispatches a queued job to mark those orders with production_status_needs_update = true
 */
class AdvanceOrderProductionStatusObserver
{
    protected AdvanceOrderRepository $repository;

    public function __construct(AdvanceOrderRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Handle the AdvanceOrder "created" event.
     * When a new OP is created, mark all related orders.
     */
    public function created(AdvanceOrder $advanceOrder): void
    {
        Log::info('AdvanceOrderProductionStatusObserver: created', [
            'advance_order_id' => $advanceOrder->id,
            'status' => $advanceOrder->status,
        ]);

        $this->markRelatedOrders($advanceOrder->id);
    }

    /**
     * Handle the AdvanceOrder "updated" event.
     * Detects:
     * - Status changes (PENDING -> EXECUTED, EXECUTED -> CANCELLED)
     * - Date changes (initial_dispatch_date, final_dispatch_date)
     */
    public function updated(AdvanceOrder $advanceOrder): void
    {
        $statusChanged = $advanceOrder->wasChanged('status');
        $datesChanged = $advanceOrder->wasChanged(['initial_dispatch_date', 'final_dispatch_date']);

        if (!$statusChanged && !$datesChanged) {
            return; // No relevant changes
        }

        Log::info('AdvanceOrderProductionStatusObserver: updated', [
            'advance_order_id' => $advanceOrder->id,
            'status_changed' => $statusChanged,
            'dates_changed' => $datesChanged,
            'old_status' => $statusChanged ? $advanceOrder->getOriginal('status') : null,
            'new_status' => $statusChanged ? $advanceOrder->status : null,
        ]);

        $this->markRelatedOrders($advanceOrder->id);
    }

    /**
     * Get all order IDs related to this AdvanceOrder via repository
     * and dispatch a job to mark them as needing update.
     */
    protected function markRelatedOrders(int $advanceOrderId): void
    {
        $orderIds = $this->repository->getRelatedOrderIds($advanceOrderId);

        if (empty($orderIds)) {
            Log::info('AdvanceOrderProductionStatusObserver: No related orders found', [
                'advance_order_id' => $advanceOrderId,
            ]);
            return;
        }

        Log::info('AdvanceOrderProductionStatusObserver: Dispatching job', [
            'advance_order_id' => $advanceOrderId,
            'order_ids_count' => count($orderIds),
        ]);

        // Dispatch job to avoid saturating the server
        MarkOrdersForProductionStatusUpdate::dispatch($orderIds);
    }
}
