<?php

namespace App\Services;

use App\Contracts\DeletionServiceInterface;
use Illuminate\Support\Facades\Log;

class NutritionalInformationDeletionService implements DeletionServiceInterface
{
    /**
     * The job class to dispatch for deletion
     *
     * @var string
     */
    protected string $jobClass;

    /**
     * Create a new service instance
     *
     * @param string $jobClass The fully qualified job class name
     */
    public function __construct(string $jobClass)
    {
        $this->jobClass = $jobClass;
    }

    /**
     * Delete a single record with all related data
     * Dispatches the deletion job to the queue
     *
     * @param int $id
     * @return bool
     */
    public function deleteSingle(int $id): bool
    {
        try {
            // Dispatch the job to the queue with single ID
            $this->jobClass::dispatch([$id]);

            Log::info("Deletion job dispatched for single record", [
                'job_class' => $this->jobClass,
                'id' => $id,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error("Error dispatching deletion job for single record", [
                'job_class' => $this->jobClass,
                'id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Delete multiple records with all related data
     * Dispatches the deletion job to the queue
     *
     * @param array $ids
     * @return int Number of IDs dispatched to job
     */
    public function deleteMultiple(array $ids): int
    {
        if (empty($ids)) {
            Log::warning("Attempted to delete multiple records with empty array", [
                'job_class' => $this->jobClass,
            ]);
            return 0;
        }

        try {
            // Dispatch the job to the queue
            $this->jobClass::dispatch($ids);

            Log::info("Deletion job dispatched for multiple records", [
                'job_class' => $this->jobClass,
                'count' => count($ids),
                'ids' => $ids,
            ]);

            return count($ids);
        } catch (\Exception $e) {
            Log::error("Error dispatching deletion job for multiple records", [
                'job_class' => $this->jobClass,
                'ids' => $ids,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 0;
        }
    }
}