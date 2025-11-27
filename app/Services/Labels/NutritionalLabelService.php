<?php

namespace App\Services\Labels;

use App\Jobs\GenerateNutritionalLabelsJob;
use App\Models\ExportProcess;
use App\Models\Product;
use App\Repositories\NutritionalInformationRepository;
use Illuminate\Support\Facades\Log;

/**
 * Service for generating nutritional labels
 *
 * Orchestrates the label generation process:
 * 1. Fetches products with nutritional information
 * 2. Creates export process record
 * 3. Dispatches job for async processing
 */
class NutritionalLabelService
{
    public function __construct(
        protected NutritionalInformationRepository $repository
    ) {}

    /**
     * Generate nutritional labels for given product IDs
     *
     * @param array $productIds Array of product IDs
     * @param string|null $elaborationDate Elaboration date in d/m/Y format
     * @param array $quantities Array with structure [product_id => quantity]. If empty, generates one label per product.
     * @return ExportProcess
     * @throws \Exception
     */
    public function generateLabels(array $productIds, ?string $elaborationDate = null, array $quantities = []): ExportProcess
    {
        $elaborationDate = $elaborationDate ?: now()->format('d/m/Y');
        $chunkSize = 100;

        // Step 1: Expand product IDs based on quantities
        // This converts [product_id => quantity] to flat array where each product appears N times
        $expandedProductIds = [];
        foreach ($productIds as $productId) {
            $quantity = $quantities[$productId] ?? 1;
            for ($i = 0; $i < $quantity; $i++) {
                $expandedProductIds[] = $productId;
            }
        }

        $totalLabels = count($expandedProductIds);

        // Step 2: Always chunk, regardless of quantity
        $chunks = array_chunk($expandedProductIds, $chunkSize);
        $totalChunks = count($chunks);

        Log::info('Nutritional labels generation - chunking strategy', [
            'total_labels' => $totalLabels,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'unique_products' => count($productIds)
        ]);

        // Step 3: Create export process for each chunk
        $exportProcesses = [];

        foreach ($chunks as $chunkIndex => $chunkProductIds) {
            $chunkNumber = $chunkIndex + 1;
            $chunkLabelCount = count($chunkProductIds);

            // Get unique product IDs in this chunk for description
            $uniqueChunkProductIds = collect($chunkProductIds)->unique()->sort()->values();
            $firstProduct = $uniqueChunkProductIds->first();
            $lastProduct = $uniqueChunkProductIds->last();

            // Create export process for this chunk
            $exportProcess = ExportProcess::create([
                'type' => ExportProcess::TYPE_NUTRITIONAL_INFORMATION,
                'description' => "Etiquetas nutricionales: Lote {$chunkNumber}/{$totalChunks} ({$chunkLabelCount} etiqueta(s), productos #{$firstProduct} a #{$lastProduct})",
                'status' => ExportProcess::STATUS_QUEUED,
                'file_url' => '-'
            ]);

            // Build quantities array for this chunk
            $chunkQuantities = [];
            foreach ($chunkProductIds as $productId) {
                $chunkQuantities[$productId] = ($chunkQuantities[$productId] ?? 0) + 1;
            }

            // Dispatch job for this chunk
            GenerateNutritionalLabelsJob::dispatch(
                array_keys($chunkQuantities),
                $elaborationDate,
                $exportProcess->id,
                $chunkQuantities
            );

            $exportProcesses[] = $exportProcess;

            Log::info('Nutritional labels chunk dispatched', [
                'export_process_id' => $exportProcess->id,
                'chunk_number' => $chunkNumber,
                'total_chunks' => $totalChunks,
                'chunk_labels' => $chunkLabelCount,
                'chunk_quantities' => $chunkQuantities
            ]);
        }

        Log::info('Nutritional labels generation initiated with chunking', [
            'total_export_processes' => count($exportProcesses),
            'total_labels' => $totalLabels,
            'elaboration_date' => $elaborationDate
        ]);

        // Return the first export process (for backward compatibility)
        // Frontend will receive the first process, others will be processed in background
        return $exportProcesses[0];
    }

    /**
     * Create export process record for labels
     *
     * @param array $productIds
     * @param int $totalLabels
     * @return ExportProcess
     */
    protected function createExportProcessForLabels(array $productIds, int $totalLabels): ExportProcess
    {
        $uniqueProductIds = collect($productIds)->unique()->sort()->values();
        $uniqueProductCount = $uniqueProductIds->count();
        $firstProduct = $uniqueProductIds->first();
        $lastProduct = $uniqueProductIds->last();

        if ($totalLabels === 1) {
            $description = "Etiqueta nutricional del producto #{$firstProduct}";
        } elseif ($uniqueProductCount === 1) {
            $description = "Etiquetas nutricionales: {$totalLabels} etiqueta(s) del producto #{$firstProduct}";
        } else {
            $description = "Etiquetas nutricionales: {$totalLabels} etiqueta(s) de {$uniqueProductCount} productos (#{$firstProduct} a #{$lastProduct})";
        }

        return ExportProcess::create([
            'type' => ExportProcess::TYPE_NUTRITIONAL_INFORMATION,
            'description' => $description,
            'status' => ExportProcess::STATUS_QUEUED,
            'file_url' => '-'
        ]);
    }
}