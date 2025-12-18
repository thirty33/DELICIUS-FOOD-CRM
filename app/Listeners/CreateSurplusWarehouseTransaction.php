<?php

namespace App\Listeners;

use App\Events\OrderLineQuantityReducedBelowProduced;
use App\Jobs\CreateSurplusWarehouseTransactionJob;
use Illuminate\Support\Facades\Log;

/**
 * Listener that dispatches a job to create a warehouse transaction when
 * an order line quantity is reduced below what was already produced.
 *
 * The actual transaction creation logic is in CreateSurplusWarehouseTransactionJob.
 *
 * NOTE: This listener is manually registered in AppServiceProvider.
 * Auto-discovery is disabled to prevent double registration.
 */
class CreateSurplusWarehouseTransaction
{
    /**
     * Disable Laravel's event auto-discovery for this listener.
     * We register it manually in AppServiceProvider.
     */
    public $disableAutoDiscovery = true;

    public function handle(OrderLineQuantityReducedBelowProduced $event): void
    {
        Log::info('CreateSurplusWarehouseTransaction: Dispatching job', [
            'order_line_id' => $event->orderLine->id,
            'old_quantity' => $event->oldQuantity,
            'new_quantity' => $event->newQuantity,
            'produced_quantity' => $event->producedQuantity,
            'surplus' => $event->surplus,
            'user_id' => $event->userId,
        ]);

        CreateSurplusWarehouseTransactionJob::dispatch(
            $event->orderLine->id,
            $event->oldQuantity,
            $event->newQuantity,
            $event->producedQuantity,
            $event->surplus,
            $event->userId
        );
    }
}
