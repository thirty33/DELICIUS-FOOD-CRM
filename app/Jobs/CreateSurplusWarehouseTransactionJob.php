<?php

namespace App\Jobs;

use App\Enums\WarehouseTransactionStatus;
use App\Models\Role;
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
 *
 * IMPORTANT: This job receives all necessary data directly (orderId, productId,
 * productName, measureUnit) because the OrderLine may be deleted before this
 * job executes when running asynchronously via queue.
 */
class CreateSurplusWarehouseTransactionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $orderLineId;
    public int $orderId;
    public int $productId;
    public string $productName;
    public string $measureUnit;
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
        int $orderId,
        int $productId,
        string $productName,
        string $measureUnit,
        int $oldQuantity,
        int $newQuantity,
        int $producedQuantity,
        int $surplus,
        ?int $userId = null
    ) {
        $this->orderLineId = $orderLineId;
        $this->orderId = $orderId;
        $this->productId = $productId;
        $this->productName = $productName;
        $this->measureUnit = $measureUnit;
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
        // NOTE: We no longer look up the OrderLine because it may have been deleted
        // before this job executes. All necessary data is passed directly.

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
            // Get user who made the change - fallback to first admin if no userId
            $userId = $this->userId;
            if (!$userId) {
                $adminUser = User::whereHas('roles', fn($q) => $q->where('roles.id', Role::ADMIN))->first();
                $userId = $adminUser?->id;
            }

            $userName = 'Sistema';
            if ($userId) {
                $user = User::find($userId);
                $userName = $user ? $user->name : 'Usuario ID ' . $userId;
            }

            // Build descriptive reason using passed data (not from OrderLine lookup)
            $reason = sprintf(
                'Sobrante por modificación de pedido #%d - Producto: %s - Cantidad reducida de %d a %d (producido: %d, sobrante: %d) - Usuario: %s',
                $this->orderId,
                $this->productName,
                $this->oldQuantity,
                $this->newQuantity,
                $this->producedQuantity,
                $this->surplus,
                $userName
            );

            // Get current stock
            $stockBefore = $warehouseRepository->getProductStockInWarehouse(
                $this->productId,
                $defaultWarehouse->id
            );

            $stockAfter = $stockBefore + $this->surplus;

            // Create transaction
            $transaction = WarehouseTransaction::create([
                'warehouse_id' => $defaultWarehouse->id,
                'user_id' => $userId,
                'transaction_code' => WarehouseTransaction::generateTransactionCode(),
                'status' => WarehouseTransactionStatus::EXECUTED,
                'reason' => $reason,
                'executed_at' => now(),
                'executed_by' => $userId,
            ]);

            Log::info('CreateSurplusWarehouseTransactionJob: Transaction created', [
                'transaction_id' => $transaction->id,
                'transaction_code' => $transaction->transaction_code,
                'order_id' => $this->orderId,
                'order_line_id' => $this->orderLineId,
                'product_id' => $this->productId,
                'product_name' => $this->productName,
                'surplus' => $this->surplus,
            ]);

            // Create transaction line
            WarehouseTransactionLine::create([
                'warehouse_transaction_id' => $transaction->id,
                'product_id' => $this->productId,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'difference' => $this->surplus,
                'unit_of_measure' => $this->measureUnit,
            ]);

            // Update warehouse stock
            $warehouseRepository->updateProductStockInWarehouse(
                $this->productId,
                $defaultWarehouse->id,
                $stockAfter
            );

            Log::info('CreateSurplusWarehouseTransactionJob: Stock updated', [
                'product_id' => $this->productId,
                'warehouse_id' => $defaultWarehouse->id,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'difference' => $this->surplus,
            ]);

            DB::commit();

            Log::info('CreateSurplusWarehouseTransactionJob: Completed successfully', [
                'transaction_id' => $transaction->id,
                'order_id' => $this->orderId,
                'surplus' => $this->surplus,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('CreateSurplusWarehouseTransactionJob: Error processing surplus', [
                'order_line_id' => $this->orderLineId,
                'order_id' => $this->orderId,
                'product_id' => $this->productId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }
}
