<?php

namespace App\Jobs;

use App\Models\ImportProcess;
use App\Models\Product;
use App\Classes\ErrorManagment\ExportErrorHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class BulkUpdateProductImages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The import process instance.
     *
     * @var ImportProcess
     */
    protected $importProcess;

    /**
     * The images to be processed.
     *
     * @var array
     */
    protected $images;

    /**
     * The original filenames.
     *
     * @var array
     */
    protected $originalFileNames;

    /**
     * Create a new job instance.
     */
    public function __construct(ImportProcess $importProcess, array $images, array $originalFileNames)
    {
        $this->importProcess = $importProcess;
        $this->images = $images;
        $this->originalFileNames = $originalFileNames;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Update import process status
            $this->importProcess->update(['status' => ImportProcess::STATUS_PROCESSING]);

            // Successful and failed upload counters
            $successCount = 0;
            $failedCount = 0;
            $processErrors = [];

            // Process each image individually
            foreach ($this->images as $index => $image) {
                try {
                    // Get the original filename
                    $fullOriginalFileName = $this->originalFileNames[$image] ?? null;
                    
                    // Remove extension
                    $originalFileName = $fullOriginalFileName 
                        ? pathinfo($fullOriginalFileName, PATHINFO_FILENAME) 
                        : null;
                    
                    if (!$originalFileName) {
                        $processErrors[] = [
                            'image_index' => $index,
                            'error' => 'Nombre de archivo original no encontrado'
                        ];
                        $failedCount++;
                        continue;
                    }

                    // Find products by original filename (without extension)
                    $products = Product::where(function($query) use ($originalFileName, $fullOriginalFileName) {
                        $query->whereRaw('LOWER(original_filename) = ?', [strtolower($originalFileName)])
                              ->orWhereRaw('LOWER(original_filename) = ?', [strtolower($fullOriginalFileName)]);
                    })->get();

                    if ($products->isEmpty()) {
                        $processErrors[] = [
                            'image_index' => $index,
                            'original_filename' => $originalFileName,
                            'error' => 'No se encontraron productos con este nombre de archivo'
                        ];
                        $failedCount++;
                        continue;
                    }

                    // Update all matching products
                    foreach ($products as $product) {
                        try {
                            // Remove previous image if exists
                            if ($product->image) {
                                try {
                                    Storage::disk('s3')->delete($product->image);
                                } catch (\Exception $storageException) {
                                    $processErrors[] = [
                                        'product_id' => $product->id,
                                        'error' => 'Error eliminando imagen anterior: ' . $storageException->getMessage()
                                    ];
                                }
                            }

                            // Update product with new image
                            $product->image = $image;
                            $product->save();

                            $successCount++;
                        } catch (\Exception $productUpdateException) {
                            $processErrors[] = [
                                'product_id' => $product->id,
                                'original_filename' => $originalFileName,
                                'error' => 'Error actualizando producto: ' . $productUpdateException->getMessage()
                            ];
                            $failedCount++;
                        }
                    }
                } catch (\Exception $individualImageException) {
                    $processErrors[] = [
                        'image_index' => $index,
                        'error' => 'Error procesando imagen: ' . $individualImageException->getMessage()
                    ];
                    $failedCount++;
                }
            }

            // Update import process status and error log
            $this->importProcess->update([
                'status' => $failedCount > 0 
                    ? ImportProcess::STATUS_PROCESSED_WITH_ERRORS 
                    : ImportProcess::STATUS_PROCESSED,
                'error_log' => $processErrors
            ]);

            // Log the results
            Log::info('Bulk Product Image Update Completed', [
                'import_process_id' => $this->importProcess->id,
                'successful_uploads' => $successCount,
                'failed_uploads' => $failedCount
            ]);
        } catch (\Exception $mainException) {
            // Use ExportErrorHandler to manage the error
            ExportErrorHandler::handle(
                $mainException, 
                $this->importProcess->id, 
                'bulk_image_upload_job'
            );
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Exception $exception): void
    {
        // Ensure the import process is marked as failed
        $this->importProcess->update([
            'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
            'error_log' => [
                [
                    'error' => 'Job failed completely',
                    'message' => $exception->getMessage(),
                    'trace' => $exception->getTraceAsString()
                ]
            ]
        ]);

        // Log the failure
        Log::error('Bulk Product Image Update Job Failed', [
            'import_process_id' => $this->importProcess->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}