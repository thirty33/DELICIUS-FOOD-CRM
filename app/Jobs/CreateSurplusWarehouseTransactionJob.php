<?php

namespace App\Jobs;

use App\Enums\WarehouseTransactionStatus;
use App\Models\OrderLine;
use App\Models\User;
use App\Models\WarehouseTransaction;
use App\Models\WarehouseTransactionLine;
use App\Repositories\WarehouseRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to create a warehouse transaction for surplus when an order line
 * quantity is reduced below what was already produced.
 *
 * This job is dispatched by CreateSurplusWarehouseTransaction listener.
 */
class CreateSurplusWarehouseTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $orderLineId;
    public int $oldQuantity;
    public int $newQuantity;
    public int $producedQuantity;
    public int $surplus;
    public ?int $userId;

    /**
     * Create a new job instance.
     */
    public function __construct(
        int $orderLineId,
        int $oldQuantity,
        int $newQuantity,
        int $producedQuantity,
        int $surplus,
        ?int $userId = null
    ) {
        $this->orderLineId = $orderLineId;
        $this->oldQuantity = $oldQuantity;
        $this->newQuantity = $newQuantity;
        $this->producedQuantity = $producedQuantity;
        $this->surplus = $surplus;
        $this->userId = $userId;
    }

    /**
     * Execute the job.
     */
    public function handle(WarehouseRepository $warehouseRepository): void
    {
        $orderLine = OrderLine::find($this->orderLineId);

        if (!$orderLine) {
            Log::warning('CreateSurplusWarehouseTransactionJob: OrderLine not found', [
                'order_line_id' => $this->orderLineId,
            ]);
            return;
        }

        if ($this->surplus <= 0) {
            Log::info('CreateSurplusWarehouseTransactionJob: No surplus to process', [
                'order_line_id' => $this->orderLineId,
                'surplus' => $this->surplus,
            ]);
            return;
        }

        $defaultWarehouse = $warehouseRepository->getDefaultWarehouse();

        if (!$defaultWarehouse) {
            Log::error('CreateSurplusWarehouseTransactionJob: No default warehouse found', [
                'order_line_id' => $this->orderLineId,
            ]);
            return;
        }

        DB::beginTransaction();
        try {
            $order = $orderLine->order;
            $product = $orderLine->product;

            // Get user who made the change
            $userName = 'Sistema';
            if ($this->userId) {
                $user = User::find($this->userId);
                $userName = $user ? $user->name : 'Usuario ID ' . $this->userId;
            }

            // Build descriptive reason
            $reason = sprintf(
                'Sobrante por modificación de pedido #%d - Producto: %s - Cantidad reducida de %d a %d (producido: %d, sobrante: %d) - Usuario: %s',
                $order->id,
                $product->name,
                $this->oldQuantity,
                $this->newQuantity,
                $this->producedQuantity,
                $this->surplus,
                $userName
            );

            // Get current stock
            $stockBefore = $warehouseRepository->getProductStockInWarehouse(
                $product->id,
                $defaultWarehouse->id
            );

            $stockAfter = $stockBefore + $this->surplus;

            // Create transaction
            $transaction = WarehouseTransaction::create([
                'warehouse_id' => $defaultWarehouse->id,
                'user_id' => $this->userId,
                'transaction_code' => WarehouseTransaction::generateTransactionCode(),
                'status' => WarehouseTransactionStatus::EXECUTED,
                'reason' => $reason,
                'executed_at' => now(),
                'executed_by' => $this->userId,
            ]);

            Log::info('CreateSurplusWarehouseTransactionJob: Transaction created', [
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->transaction_code,
                'order_id' => $order->id,
                'order_line_id' => $orderLine->id,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'surplus' => $this->surplus,
            ]);

            // Create transaction line
            WarehouseTransactionLine::create([
                'warehouse_transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'difference' => $this->surplus,
                'unit_of_measure' => $product->measure_unit ?? 'UND',
            ]);

            // Update warehouse stock
            $warehouseRepository->updateProductStockInWarehouse(
                $product->id,
                $defaultWarehouse->id,
                $stockAfter
            );

            Log::info('CreateSurplusWarehouseTransactionJob: Stock updated', [
                'product_id' => $product->id,
                'warehouse_id' => $defaultWarehouse->id,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'difference' => $this->surplus,
            ]);

            DB::commit();

            Log::info('CreateSurplusWarehouseTransactionJob: Completed successfully', [
                'transaction_id' => $transaction->id,
                'order_id' => $order->id,
                'surplus' => $this->surplus,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('CreateSurplusWarehouseTransactionJob: Error processing surplus', [
                'order_line_id' => $this->orderLineId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
