<?php

namespace App\Jobs;

use App\Models\NutritionalInformation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DeleteNutritionalInformationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * The nutritional information IDs to delete
     *
     * @var array
     */
    protected array $nutritionalInformationIds;

    /**
     * Create a new job instance.
     *
     * @param array $nutritionalInformationIds
     */
    public function __construct(array $nutritionalInformationIds)
    {
        $this->nutritionalInformationIds = $nutritionalInformationIds;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle(): void
    {
        Log::info("Starting DeleteNutritionalInformationJob", [
            'ids' => $this->nutritionalInformationIds,
            'count' => count($this->nutritionalInformationIds),
        ]);

        $deletedCount = 0;

        try {
            DB::transaction(function () use (&$deletedCount) {
                $nutritionalInfoRecords = NutritionalInformation::whereIn('id', $this->nutritionalInformationIds)->get();

                foreach ($nutritionalInfoRecords as $nutritionalInfo) {
                    // Delete all related nutritional values
                    $valuesDeleted = $nutritionalInfo->nutritionalValues()->delete();

                    // Delete the nutritional information record
                    $nutritionalInfo->delete();

                    $deletedCount++;

                    Log::debug("Deleted nutritional information", [
                        'nutritional_information_id' => $nutritionalInfo->id,
                        'product_id' => $nutritionalInfo->product_id,
                        'nutritional_values_deleted' => $valuesDeleted,
                    ]);
                }

                Log::info("DeleteNutritionalInformationJob completed successfully", [
                    'requested_count' => count($this->nutritionalInformationIds),
                    'deleted_count' => $deletedCount,
                ]);
            });
        } catch (\Exception $e) {
            Log::error("DeleteNutritionalInformationJob failed during transaction", [
                'ids' => $this->nutritionalInformationIds,
                'deleted_count' => $deletedCount,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     *
     * @param \Throwable $exception
     * @return void
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("DeleteNutritionalInformationJob failed permanently", [
            'ids' => $this->nutritionalInformationIds,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}