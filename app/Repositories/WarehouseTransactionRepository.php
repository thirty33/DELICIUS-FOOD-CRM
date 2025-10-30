<?php

namespace App\Repositories;

use App\Models\WarehouseTransaction;
use App\Models\Warehouse;
use App\Enums\WarehouseTransactionStatus;
use Illuminate\Support\Facades\DB;

class WarehouseTransactionRepository
{
    public function executeTransaction(WarehouseTransaction $transaction, int $userId): bool
    {
        if (!$transaction->canExecute()) {
            return false;
        }

        DB::beginTransaction();
        try {
            foreach ($transaction->lines as $line) {
                DB::table('warehouse_product')
                    ->where('warehouse_id', $transaction->warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->update([
                        'stock' => $line->stock_after,
                        'updated_at' => now(),
                    ]);
            }

            $transaction->update([
                'status' => WarehouseTransactionStatus::EXECUTED,
                'executed_at' => now(),
                'executed_by' => $userId,
            ]);

            DB::commit();
            return true;
        } catch (\Exception) {
            DB::rollBack();
            return false;
        }
    }

    public function cancelTransaction(WarehouseTransaction $transaction, int $userId, string $reason): bool
    {
        if (!$transaction->canCancel()) {
            return false;
        }

        DB::beginTransaction();
        try {
            foreach ($transaction->lines as $line) {
                DB::table('warehouse_product')
                    ->where('warehouse_id', $transaction->warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->update([
                        'stock' => $line->stock_before,
                        'updated_at' => now(),
                    ]);
            }

            $transaction->update([
                'status' => WarehouseTransactionStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $userId,
                'cancellation_reason' => $reason,
            ]);

            DB::commit();
            return true;
        } catch (\Exception) {
            DB::rollBack();
            return false;
        }
    }

    public function getPendingTransactions(int $warehouseId = null)
    {
        $query = WarehouseTransaction::with(['warehouse', 'user', 'lines.product'])
            ->where('status', WarehouseTransactionStatus::PENDING);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    public function getTransactionHistory(int $warehouseId = null)
    {
        $query = WarehouseTransaction::with(['warehouse', 'user', 'executedBy', 'cancelledBy', 'lines.product']);

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $query->orderBy('created_at', 'desc')->get();
    }
}
