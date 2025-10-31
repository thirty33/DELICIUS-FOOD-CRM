<?php

namespace App\Listeners;

use App\Enums\WarehouseTransactionStatus;
use App\Events\AdvanceOrderCancelled;
use App\Models\WarehouseTransaction;
use App\Repositories\WarehouseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CancelWarehouseTransactionForAdvanceOrder
{
    protected WarehouseRepository $warehouseRepository;

    /**
     * Create the event listener.
     */
    public function __construct(WarehouseRepository $warehouseRepository)
    {
        $this->warehouseRepository = $warehouseRepository;
    }

    /**
     * Handle the event.
     */
    public function handle(AdvanceOrderCancelled $event): void
    {
        $advanceOrder = $event->advanceOrder;

        DB::beginTransaction();
        try {
            // Find transaction associated with this advance order
            $transaction = WarehouseTransaction::where('advance_order_id', $advanceOrder->id)->first();

            if (!$transaction) {
                Log::warning('No warehouse transaction found for cancelled advance order', [
                    'advance_order_id' => $advanceOrder->id,
                ]);
                return;
            }

            // Only revert stock if transaction was executed
            if ($transaction->status === WarehouseTransactionStatus::EXECUTED) {
                foreach ($transaction->lines as $line) {
                    // Revert to stock_before (undo the execution)
                    $this->warehouseRepository->updateProductStockInWarehouse(
                        $line->product_id,
                        $transaction->warehouse_id,
                        $line->stock_before
                    );
                }
            }

            // Update transaction status to CANCELLED
            $transaction->update([
                'status' => WarehouseTransactionStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => auth()->id(),
                'cancellation_reason' => "Cancelación de Orden de Producción #{$advanceOrder->id}",
            ]);

            DB::commit();

            Log::info('Warehouse transaction cancelled for advance order', [
                'advance_order_id' => $advanceOrder->id,
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->transaction_code,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error cancelling warehouse transaction for advance order', [
                'advance_order_id' => $advanceOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
