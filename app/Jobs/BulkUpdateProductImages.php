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
                            'row' => $index,
                            'attribute' => 'image_processing',
                            'errors' => ['Nombre de archivo original no encontrado'],
                            'values' => [
                                'image' => $image,
                                'original_filename' => null
                            ]
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
                            'row' => $index,
                            'attribute' => 'product_matching',
                            'errors' => ['No se encontraron productos con este nombre de archivo'],
                            'values' => [
                                'image' => $image,
                                'original_filename' => $originalFileName
                            ]
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
                                        'row' => $index,
                                        'attribute' => 'storage_deletion',
                                        'errors' => ['Error eliminando imagen anterior: ' . $storageException->getMessage()],
                                        'values' => [
                                            'product_id' => $product->id,
                                            'image' => $product->image,
                                            'file' => $storageException->getFile(),
                                            'line' => $storageException->getLine()
                                        ]
                                    ];
                                }
                            }

                            // Update product with new image
                            $product->image = $image;
                            $product->save();

                            $successCount++;
                        } catch (\Exception $productUpdateException) {
                            $processErrors[] = [
                                'row' => $index,
                                'attribute' => 'product_update',
                                'errors' => ['Error actualizando producto: ' . $productUpdateException->getMessage()],
                                'values' => [
                                    'product_id' => $product->id,
                                    'original_filename' => $originalFileName,
                                    'file' => $productUpdateException->getFile(),
                                    'line' => $productUpdateException->getLine(),
                                    'trace' => $productUpdateException->getTraceAsString()
                                ]
                            ];
                            $failedCount++;
                        }
                    }
                } catch (\Exception $individualImageException) {
                    $processErrors[] = [
                        'row' => $index,
                        'attribute' => 'image_processing',
                        'errors' => ['Error procesando imagen: ' . $individualImageException->getMessage()],
                        'values' => [
                            'image' => $image,
                            'file' => $individualImageException->getFile(),
                            'line' => $individualImageException->getLine(),
                            'trace' => $individualImageException->getTraceAsString()
                        ]
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
            $this->handleMainError($mainException);
        }
    }

    /**
     * Handle main process error
     */
    private function handleMainError(\Throwable $e): void
    {
        $error = [
            'row' => 0,
            'attribute' => 'bulk_image_upload_job',
            'errors' => [$e->getMessage()],
            'values' => [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]
        ];

        // Update the import process with error information
        if ($this->importProcess) {
            $existingErrors = $this->importProcess->error_log ?? [];
            $existingErrors[] = $error;
            
            $this->importProcess->update([
                'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
                'error_log' => $existingErrors
            ]);
        }

        // Log the error
        Log::error('Error en proceso de actualización de imágenes', [
            'import_process_id' => $this->importProcess ? $this->importProcess->id : 'unknown',
            'context' => 'bulk_image_upload_job',
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $error = [
            'row' => 0,
            'attribute' => 'job_failure',
            'errors' => ['Job failed completely: ' . $exception->getMessage()],
            'values' => [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString()
            ]
        ];

        // Ensure the import process is marked as failed
        if ($this->importProcess) {
            $existingErrors = $this->importProcess->error_log ?? [];
            $existingErrors[] = $error;
            
            $this->importProcess->update([
                'status' => ImportProcess::STATUS_PROCESSED_WITH_ERRORS,
                'error_log' => $existingErrors
            ]);
        }

        // Log the failure
        Log::error('Bulk Product Image Update Job Failed', [
            'import_process_id' => $this->importProcess ? $this->importProcess->id : 'unknown',
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}