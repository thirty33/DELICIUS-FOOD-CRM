<?php

namespace App\Repositories;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Generic repository for managing import_record_tracking table
 *
 * This table tracks which records were created/updated during an import process,
 * allowing cleanup of old records that are not in the import file.
 *
 * IMPORTANT: All operations are isolated by import_process_id and record_type
 * to prevent interference between concurrent import processes.
 *
 * USAGE EXAMPLES:
 *
 * For Order Lines Import:
 * - record_type: 'order_line'
 * - parent_id: order_id
 * - record_id: order_line_id
 *
 * For Plated Dish Ingredients Import:
 * - record_type: 'plated_dish_ingredient'
 * - parent_id: plated_dish_id
 * - record_id: plated_dish_ingredient_id
 */
class ImportRecordTrackingRepository
{
    /**
     * Track a record that was created/updated during import
     *
     * @param int $importProcessId
     * @param string $recordType Type of record (e.g., 'order_line', 'plated_dish_ingredient')
     * @param int|null $parentId Parent entity ID (e.g., order_id, plated_dish_id)
     * @param int $recordId The record's primary key
     * @return void
     */
    public function track(int $importProcessId, string $recordType, ?int $parentId, int $recordId): void
    {
        DB::table('import_record_tracking')->insert([
            'import_process_id' => $importProcessId,
            'record_type' => $recordType,
            'parent_id' => $parentId,
            'record_id' => $recordId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Get all tracked record IDs for a specific import process and record type
     *
     * @param int $importProcessId
     * @param string $recordType
     * @return array
     */
    public function getTrackedRecordIds(int $importProcessId, string $recordType): array
    {
        return DB::table('import_record_tracking')
            ->where('import_process_id', $importProcessId)
            ->where('record_type', $recordType)
            ->pluck('record_id')
            ->toArray();
    }

    /**
     * Get all parent IDs that were affected by a specific import process
     *
     * @param int $importProcessId
     * @param string $recordType
     * @return array
     */
    public function getAffectedParentIds(int $importProcessId, string $recordType): array
    {
        return DB::table('import_record_tracking')
            ->where('import_process_id', $importProcessId)
            ->where('record_type', $recordType)
            ->whereNotNull('parent_id')
            ->distinct()
            ->pluck('parent_id')
            ->toArray();
    }

    /**
     * Get all tracked record IDs for a specific parent
     *
     * @param int $importProcessId
     * @param string $recordType
     * @param int $parentId
     * @return array
     */
    public function getTrackedRecordIdsByParent(int $importProcessId, string $recordType, int $parentId): array
    {
        return DB::table('import_record_tracking')
            ->where('import_process_id', $importProcessId)
            ->where('record_type', $recordType)
            ->where('parent_id', $parentId)
            ->pluck('record_id')
            ->toArray();
    }

    /**
     * Clean up (delete) all tracking records for a specific import process
     *
     * This should be called after import completion (success or failure)
     * to prevent accumulation of orphaned tracking records.
     *
     * @param int $importProcessId
     * @param string|null $recordType Optional: clean only specific record type
     * @return int Number of deleted records
     */
    public function cleanup(int $importProcessId, ?string $recordType = null): int
    {
        try {
            $query = DB::table('import_record_tracking')
                ->where('import_process_id', $importProcessId);

            if ($recordType !== null) {
                $query->where('record_type', $recordType);
            }

            $deleted = $query->delete();

            Log::info('Cleaned up import record tracking', [
                'import_process_id' => $importProcessId,
                'record_type' => $recordType ?? 'all',
                'deleted_records' => $deleted,
            ]);

            return $deleted;
        } catch (\Exception $e) {
            Log::error('Failed to cleanup import record tracking', [
                'import_process_id' => $importProcessId,
                'record_type' => $recordType ?? 'all',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if there are any tracked records for a specific import process
     *
     * @param int $importProcessId
     * @param string|null $recordType Optional: check only specific record type
     * @return bool
     */
    public function hasTrackedRecords(int $importProcessId, ?string $recordType = null): bool
    {
        $query = DB::table('import_record_tracking')
            ->where('import_process_id', $importProcessId);

        if ($recordType !== null) {
            $query->where('record_type', $recordType);
        }

        return $query->exists();
    }

    /**
     * Get count of tracked records for a specific import process
     *
     * @param int $importProcessId
     * @param string|null $recordType Optional: count only specific record type
     * @return int
     */
    public function countTrackedRecords(int $importProcessId, ?string $recordType = null): int
    {
        $query = DB::table('import_record_tracking')
            ->where('import_process_id', $importProcessId);

        if ($recordType !== null) {
            $query->where('record_type', $recordType);
        }

        return $query->count();
    }
}