<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Repository for managing import_order_line_tracking table
 *
 * This table tracks which order lines were created/updated during an import process,
 * allowing cleanup of old lines that are not in the import file.
 *
 * IMPORTANT: All operations are isolated by import_process_id to prevent
 * interference between concurrent import processes.
 */
class ImportOrderLineTrackingRepository
{
    /**
     * Track an order line that was created/updated during import
     *
     * @param int $importProcessId
     * @param int $orderId
     * @param int $orderLineId
     * @return void
     */
    public function track(int $importProcessId, int $orderId, int $orderLineId): void
    {
        DB::table('import_order_line_tracking')->insert([
            'import_process_id' => $importProcessId,
            'order_id' => $orderId,
            'order_line_id' => $orderLineId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Get all tracked order line IDs for a specific import process
     *
     * @param int $importProcessId
     * @return array
     */
    public function getTrackedOrderLineIds(int $importProcessId): array
    {
        return DB::table('import_order_line_tracking')
            ->where('import_process_id', $importProcessId)
            ->pluck('order_line_id')
            ->toArray();
    }

    /**
     * Get all order IDs that were affected by a specific import process
     *
     * @param int $importProcessId
     * @return array
     */
    public function getAffectedOrderIds(int $importProcessId): array
    {
        return DB::table('import_order_line_tracking')
            ->where('import_process_id', $importProcessId)
            ->distinct()
            ->pluck('order_id')
            ->toArray();
    }

    /**
     * Clean up (delete) all tracking records for a specific import process
     *
     * This should be called after import completion (success or failure)
     * to prevent accumulation of orphaned tracking records.
     *
     * @param int $importProcessId
     * @return int Number of deleted records
     */
    public function cleanup(int $importProcessId): int
    {
        try {
            $deleted = DB::table('import_order_line_tracking')
                ->where('import_process_id', $importProcessId)
                ->delete();

            Log::info('Cleaned up import order line tracking', [
                'import_process_id' => $importProcessId,
                'deleted_records' => $deleted,
            ]);

            return $deleted;
        } catch (\Exception $e) {
            Log::error('Failed to cleanup import order line tracking', [
                'import_process_id' => $importProcessId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if there are any tracked records for a specific import process
     *
     * @param int $importProcessId
     * @return bool
     */
    public function hasTrackedRecords(int $importProcessId): bool
    {
        return DB::table('import_order_line_tracking')
            ->where('import_process_id', $importProcessId)
            ->exists();
    }

    /**
     * Get count of tracked records for a specific import process
     *
     * @param int $importProcessId
     * @return int
     */
    public function countTrackedRecords(int $importProcessId): int
    {
        return DB::table('import_order_line_tracking')
            ->where('import_process_id', $importProcessId)
            ->count();
    }
}
