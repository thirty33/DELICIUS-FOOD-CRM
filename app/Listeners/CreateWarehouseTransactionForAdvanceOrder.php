<?php

namespace App\Listeners;

use App\Enums\WarehouseTransactionStatus;
use App\Events\AdvanceOrderExecuted;
use App\Models\WarehouseTransaction;
use App\Models\WarehouseTransactionLine;
use App\Repositories\WarehouseRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CreateWarehouseTransactionForAdvanceOrder
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
    public function handle(AdvanceOrderExecuted $event): void
    {
        $advanceOrder = $event->advanceOrder;
        $defaultWarehouse = $this->warehouseRepository->getDefaultWarehouse();

        if (!$defaultWarehouse) {
            Log::error('No default warehouse found for advance order execution', [
                'advance_order_id' => $advanceOrder->id,
            ]);
            return;
        }

        DB::beginTransaction();
        try {
            // Check if transaction already exists for this advance order
            $transaction = WarehouseTransaction::where('advance_order_id', $advanceOrder->id)->first();

            if ($transaction) {
                // Update existing transaction
                Log::info('Updating existing warehouse transaction for advance order', [
                    'advance_order_id' => $advanceOrder->id,
                    'transaction_id' => $transaction->id,
                    'old_status' => $transaction->status->value,
                    'new_status' => WarehouseTransactionStatus::EXECUTED->value,
                ]);

                // Revert previous stock changes if transaction was executed before
                if ($transaction->status === WarehouseTransactionStatus::EXECUTED) {
                    foreach ($transaction->lines as $line) {
                        $this->warehouseRepository->updateProductStockInWarehouse(
                            $line->product_id,
                            $defaultWarehouse->id,
                            $line->stock_before
                        );
                    }
                }

                // Delete old lines
                $transaction->lines()->delete();
            } else {
                // Create new transaction
                $transaction = WarehouseTransaction::create([
                    'warehouse_id' => $defaultWarehouse->id,
                    'advance_order_id' => $advanceOrder->id,
                    'user_id' => auth()->id(),
                    'transaction_code' => WarehouseTransaction::generateTransactionCode(),
                    'status' => WarehouseTransactionStatus::EXECUTED,
                    'reason' => "Ejecución de Orden de Producción #{$advanceOrder->id}",
                    'executed_at' => now(),
                    'executed_by' => auth()->id(),
                ]);

                Log::info('Created new warehouse transaction for advance order', [
                    'advance_order_id' => $advanceOrder->id,
                    'transaction_id' => $transaction->id,
                    'transaction_code' => $transaction->transaction_code,
                ]);
            }

            // Update transaction status to match advance order
            $transaction->update([
                'status' => WarehouseTransactionStatus::EXECUTED,
                'executed_at' => now(),
                'executed_by' => auth()->id(),
            ]);

            // Create/update transaction lines for each product in advance order
            $productsProcessed = 0;
            foreach ($advanceOrder->advanceOrderProducts as $advanceOrderProduct) {
                $productId = $advanceOrderProduct->product_id;

                // Get current stock (inventario inicial)
                $stockBefore = $this->warehouseRepository->getProductStockInWarehouse(
                    $productId,
                    $defaultWarehouse->id
                );

                // Calculate final inventory using formula:
                // inventario_final = inventario_inicial + total_to_produce - ordered_quantity_new
                $stockAfter = $stockBefore + $advanceOrderProduct->total_to_produce - $advanceOrderProduct->ordered_quantity_new;

                $difference = $stockAfter - $stockBefore;

                Log::debug('Processing warehouse transaction line', [
                    'advance_order_id' => $advanceOrder->id,
                    'product_id' => $productId,
                    'product_code' => $advanceOrderProduct->product->code ?? 'N/A',
                    'stock_before' => $stockBefore,
                    'total_to_produce' => $advanceOrderProduct->total_to_produce,
                    'ordered_quantity_new' => $advanceOrderProduct->ordered_quantity_new,
                    'stock_after' => $stockAfter,
                    'difference' => $difference,
                    'formula' => "stock_after = {$stockBefore} + {$advanceOrderProduct->total_to_produce} - {$advanceOrderProduct->ordered_quantity_new} = {$stockAfter}",
                ]);

                // Create transaction line
                WarehouseTransactionLine::create([
                    'warehouse_transaction_id' => $transaction->id,
                    'product_id' => $productId,
                    'stock_before' => $stockBefore,
                    'stock_after' => $stockAfter,
                    'difference' => $difference,
                    'unit_of_measure' => 'UND',
                ]);

                // Update warehouse stock
                $this->warehouseRepository->updateProductStockInWarehouse(
                    $productId,
                    $defaultWarehouse->id,
                    $stockAfter
                );

                Log::debug('Warehouse stock updated', [
                    'product_id' => $productId,
                    'warehouse_id' => $defaultWarehouse->id,
                    'new_stock' => $stockAfter,
                ]);

                $productsProcessed++;
            }

            Log::info('All warehouse transaction lines processed', [
                'advance_order_id' => $advanceOrder->id,
                'products_processed' => $productsProcessed,
            ]);

            DB::commit();

            Log::info('Warehouse transaction processed successfully for advance order', [
                'advance_order_id' => $advanceOrder->id,
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->transaction_code,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error processing warehouse transaction for advance order', [
                'advance_order_id' => $advanceOrder->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
